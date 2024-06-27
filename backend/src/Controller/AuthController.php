<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use App\Entity\User;

class AuthController extends AbstractController
{
    #[Route('/api/login', 'api_login', ['POST'])]
    public function login(Request $request, JWTTokenManagerInterface $JWTManager, UserPasswordEncoderInterface $encoder): JsonResponse
    {
        $credentials = json_decode($request->getContent(), true);
        $user = $this->getDoctrine()->getRepository(User::class)->findOneBy(['username' => $credentials['username']]);

        if (!$user || !$encoder->isPasswordValid($user, $credentials['password'])) {
            return new JsonResponse(['error' => 'Invalid credentials'], 401);
        }

        return new JsonResponse(['token' => $JWTManager->create($user)]);
    }

    #[Route('/api/register', 'api_register', ['POST'])]
    public function register(Request $request, UserPasswordEncoderInterface $encoder): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $user = new User();
        $user->setUsername($data['username']);
        $user->setPassword($encoder->encodePassword($user, $data['password']));

        $em = $this->getDoctrine()->getManager();
        $em->persist($user);
        $em->flush();

        return new JsonResponse(['status' => 'User created'], 201);
    }
}
