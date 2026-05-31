<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ImportRunRepository;
use App\Repository\SourceRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class AdminController extends AbstractController
{
    #[Route('', name: 'admin_home')]
    public function home(): RedirectResponse
    {
        return $this->redirectToRoute('admin_imports');
    }

    /** Import monitoring: latest run per source + recent run history. */
    #[Route('/imports', name: 'admin_imports', methods: ['GET'])]
    public function imports(SourceRepository $sources, ImportRunRepository $runs): Response
    {
        $latest = $runs->findLatestPerSource();
        $allSources = $sources->findBy([], ['name' => 'ASC']);

        // Sort sources: enabled first, then by latest run time desc.
        usort($allSources, static function ($a, $b) use ($latest) {
            if ($a->isEnabled() !== $b->isEnabled()) {
                return $a->isEnabled() ? -1 : 1;
            }
            $ra = $latest[$a->getId()] ?? null;
            $rb = $latest[$b->getId()] ?? null;
            $ta = $ra?->getStartedAt()?->getTimestamp() ?? 0;
            $tb = $rb?->getStartedAt()?->getTimestamp() ?? 0;

            return $tb <=> $ta;
        });

        return $this->render('admin/imports.html.twig', [
            'sources' => $allSources,
            'latest' => $latest,
            'recent' => $runs->findRecent(40),
        ]);
    }

    /** Change the logged-in admin's e-mail and/or password. */
    #[Route('/konto', name: 'admin_account', methods: ['GET', 'POST'])]
    public function account(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        UserRepository $users,
        Security $security,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $error = null;

        if ($request->isMethod('POST')) {
            $current = (string) $request->request->get('current_password');
            $email = trim((string) $request->request->get('email'));
            $new = (string) $request->request->get('new_password');
            $confirm = (string) $request->request->get('new_password_confirm');

            if (!$this->isCsrfTokenValid('account', (string) $request->request->get('_token'))) {
                $error = 'Ungültiges Formular, bitte erneut versuchen.';
            } elseif (!$hasher->isPasswordValid($user, $current)) {
                $error = 'Das aktuelle Passwort stimmt nicht.';
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $error = 'Bitte eine gültige E-Mail-Adresse angeben.';
            } elseif ($email !== $user->getEmail() && $users->findByEmail($email) !== null) {
                $error = 'Diese E-Mail-Adresse ist bereits vergeben.';
            } elseif ($new !== '' && mb_strlen($new) < 8) {
                $error = 'Das neue Passwort muss mindestens 8 Zeichen haben.';
            } elseif ($new !== '' && $new !== $confirm) {
                $error = 'Die neuen Passwörter stimmen nicht überein.';
            } else {
                $user->setEmail($email);
                if ($new !== '') {
                    $user->setPassword($hasher->hashPassword($user, $new));
                }
                $em->flush();
                // Re-establish the session so changing the identifier/password
                // doesn't log the user out.
                $security->login($user);
                $this->addFlash('success', 'Konto aktualisiert.');

                return $this->redirectToRoute('admin_account');
            }
        }

        return $this->render('admin/account.html.twig', [
            'email' => $user->getEmail(),
            'error' => $error,
        ]);
    }
}
