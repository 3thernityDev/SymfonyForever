<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_characters');
        }

        return $this->redirectToRoute('app_login');
    }

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_characters');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
    ): Response {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_characters');
        }

        $error = null;
        $username = '';
        $email = '';

        if ($request->isMethod('POST')) {
            $username = trim((string) $request->request->get('username', ''));
            $email = trim((string) $request->request->get('email', ''));
            $password = (string) $request->request->get('password', '');
            $token = (string) $request->request->get('_csrf_token', '');

            if (!$this->isCsrfTokenValid('register_form', $token)) {
                $error = 'Jeton de sécurité invalide. Veuillez réessayer.';
            } elseif (!preg_match('/^[a-zA-Z0-9_-]{3,24}$/', $username)) {
                $error = 'Le pseudo doit contenir entre 3 et 24 caractères (lettres, chiffres, _ ou -).';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Adresse e-mail invalide.';
            } elseif (strlen($password) < 6) {
                $error = 'Le mot de passe doit contenir au moins 6 caractères.';
            } elseif ($userRepository->findByUsernameOrEmail($username, $email) !== null) {
                $error = 'Ce pseudo ou cette adresse e-mail est déjà utilisé.';
            } else {
                $user = (new User())
                    ->setUsername($username)
                    ->setEmail($email);
                $user->setPassword($passwordHasher->hashPassword($user, $password));

                $em->persist($user);
                $em->flush();

                $this->addFlash('success', 'Compte créé ! Connectez-vous pour rejoindre l\'île de La Réunion.');

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('security/register.html.twig', [
            'error' => $error,
            'username' => $username,
            'email' => $email,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Géré par le firewall Symfony.');
    }
}
