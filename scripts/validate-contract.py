"""Validate OpenAPI/examples; --api additionally exercises a disposable test API."""
import argparse
import json
import re
import secrets
import sys
import urllib.error
import urllib.request
from pathlib import Path

import yaml
from jsonschema import Draft4Validator, FormatChecker
from openapi_spec_validator import validate_spec

ROOT = Path(__file__).resolve().parent.parent
SPEC = yaml.safe_load((ROOT / "docs/openapi.yaml").read_text())


def resolve(value):
    while isinstance(value, dict) and "$ref" in value:
        ref = value["$ref"]
        if not ref.startswith("#/"):
            raise ValueError("Only repository-local schema references are supported")
        value = SPEC
        for part in ref[2:].split("/"):
            value = value[part.replace("~1", "/").replace("~0", "~")]
    return value


def json_schema(value):
    if isinstance(value, list):
        return [json_schema(x) for x in value]
    if not isinstance(value, dict):
        return value
    out = {key: json_schema(item) for key, item in value.items() if key != "nullable"}
    return {"anyOf": [out, {"type": "null"}]} if value.get("nullable") else out


SCHEMA_ROOT = json_schema(SPEC)


def check(schema, value, label):
    errors = list(Draft4Validator({**SCHEMA_ROOT, "allOf": [json_schema(schema)]}, format_checker=FormatChecker()).iter_errors(value))
    if errors:
        # Do not print instances: HTTP responses can contain a JWT.
        error = errors[0]
        raise ValueError(f"{label}: schema {error.validator}, path {list(error.absolute_path)}")


def documented_examples():
    count = 0
    for route, operations in SPEC["paths"].items():
        for method, operation in operations.items():
            if method not in {"get", "post", "put", "delete", "patch", "options", "head"}:
                continue
            containers = []
            if "requestBody" in operation:
                containers.append(resolve(operation["requestBody"]))
            containers += [resolve(response) for response in operation.get("responses", {}).values()]
            for container in containers:
                for media in container.get("content", {}).values():
                    examples = [example["value"] for item in media.get("examples", {}).values() if "value" in (example := resolve(item))]
                    if "example" in media:
                        examples.append(media["example"])
                    for example in examples:
                        check(media["schema"], example, f"Example {method} {route}")
                        count += 1
    return count


def live_contract(base, origin):
    base = base.rstrip("/")
    if not base.endswith("/api") or not base.startswith(("http://", "https://")):
        raise ValueError("--api must identify a disposable HTTP(S) API ending in /api")
    observations = []
    preflight = urllib.request.Request(base + "/tasks", method="OPTIONS", headers={"Origin": origin, "Access-Control-Request-Method": "PUT", "Access-Control-Request-Headers": "authorization,content-type"})
    with urllib.request.urlopen(preflight, timeout=15) as response:
        if response.headers.get("Access-Control-Allow-Origin") != origin or "PUT" not in response.headers.get("Access-Control-Allow-Methods", ""):
            raise ValueError("CORS preflight rejected the configured frontend origin")

    def request(method, path, template, status, data=None, token=None, raw=None, media="application/json"):
        headers = {"Content-Type": media, "Origin": origin}
        if token:
            headers["Authorization"] = "Bearer " + token
        payload = raw if raw is not None else (json.dumps(data).encode() if data is not None else None)
        call = urllib.request.Request(base + path, data=payload, method=method, headers=headers)
        try:
            response = urllib.request.urlopen(call, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        content = response.read()
        if response.headers.get("Access-Control-Allow-Origin") != origin:
            raise ValueError("CORS did not allow the configured frontend origin")
        if response.status != status:
            raise ValueError(f"HTTP {method} {template}: expected {status}, received {response.status}")
        operation = SPEC["paths"]["/api" + template][method.lower()]
        documented = resolve(operation["responses"][str(status)])
        request_id = response.headers.get("X-Request-ID", "")
        if not re.fullmatch("[a-f0-9]{32}", request_id) or "no-store" not in response.headers.get("Cache-Control", ""):
            raise ValueError(f"HTTP headers {method} {template}")
        if status == 204:
            if content:
                raise ValueError("DELETE 204 must have an empty body")
            body = None
        else:
            if "application/json" not in response.headers.get("Content-Type", ""):
                raise ValueError("Expected JSON response")
            body = json.loads(content)
            check(documented["content"]["application/json"]["schema"], body, f"HTTP {method} {template} {status}")
            if status >= 400 and body["requestId"] != request_id:
                raise ValueError("Error request ID differs from header")
        observations.append({"method": method, "path": template, "status": status})
        return body, response.headers

    password = "contract-fixture-" + secrets.token_hex(8)
    users = []
    for _ in range(2):
        username = "contract_" + secrets.token_hex(6)
        request("POST", "/register", "/register", 201, {"username": username, "password": password})
        session, _ = request("POST", "/login", "/login", 200, {"username": username, "password": password})
        users.append(session["token"])
    a, b = users
    request("GET", "/health", "/health", 200)
    request("GET", "/me", "/me", 200, token=a)
    request("GET", "/tasks", "/tasks", 401)
    empty, _ = request("GET", "/tasks", "/tasks", 200, token=a)
    if empty["pagination"]["total"] != 0:
        raise ValueError("Fixture account should start empty")
    task, headers = request("POST", "/tasks", "/tasks", 201, {"title": "  Conferir contrato  ", "description": None}, a)
    location = "/tasks/" + str(task["id"])
    if headers.get("Location") != "/api" + location or task["title"] != "Conferir contrato":
        raise ValueError("Creation normalization/Location mismatch")
    request("GET", location, "/tasks/{id}", 200, token=a)
    updated, _ = request("PUT", location, "/tasks/{id}", 200, {"title": "Contrato revisado", "description": "Detalhes"}, a)
    if updated["createdAt"] != task["createdAt"]:
        raise ValueError("Editing changed createdAt")
    for title in ["Segunda", "Terceira"]:
        request("POST", "/tasks", "/tasks", 201, {"title": title}, a)
    seen = []
    for page in [1, 2, 3, 4]:
        result, _ = request("GET", f"/tasks?page={page}&limit=1", "/tasks", 200, token=a)
        seen += [item["id"] for item in result["items"]]
        if result["pagination"] != {"page": page, "limit": 1, "total": 3, "totalPages": 3}:
            raise ValueError("Pagination metadata mismatch")
    if len(set(seen)) != 3 or len(seen) != 3:
        raise ValueError("Stable pagination repeated or omitted records")
    other, _ = request("GET", "/tasks", "/tasks", 200, token=b)
    if other["items"] or other["pagination"]["total"]:
        raise ValueError("Ownership leak")
    for method in ["GET", "PUT", "DELETE"]:
        request(method, location, "/tasks/{id}", 404, {"title": "Forbidden", "description": None} if method == "PUT" else None, b)
    request("POST", "/tasks", "/tasks", 400, token=a, raw=b"{")
    request("POST", "/tasks", "/tasks", 415, token=a, raw=b"{}", media="text/plain")
    request("POST", "/tasks", "/tasks", 422, {"title": ""}, a)
    request("POST", "/tasks", "/tasks", 422, {"title": "Title", "userId": 1}, a)
    request("GET", "/tasks?page=0", "/tasks", 422, token=a)
    request("PUT", location, "/tasks/{id}", 422, {"title": "Missing description"}, a)
    request("DELETE", location, "/tasks/{id}", 204, token=a)
    request("GET", location, "/tasks/{id}", 404, token=a)
    return observations


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--api", help="Optional disposable API; creates fixture accounts/tasks")
    parser.add_argument("--output", help="JSON report path")
    parser.add_argument("--origin", default="http://localhost:4200", help="Frontend origin used by the CORS probe")
    args = parser.parse_args()
    validate_spec(SPEC)
    report = {"contract": SPEC["info"]["version"], "examples": documented_examples(), "http": live_contract(args.api, args.origin) if args.api else []}
    if args.output:
        Path(args.output).write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps({"contract": report["contract"], "validExamples": report["examples"], "validHttpResponses": len(report["http"])}, ensure_ascii=False))


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(f"Contract check failed: {type(error).__name__}: {error}", file=sys.stderr)
        sys.exit(1)
