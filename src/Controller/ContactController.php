<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\ContactClassifier;
use App\Entity\ContactMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public contact form handler. Layered, cheap-to-expensive spam defence:
 * CSRF → honeypot → time-trap → per-IP rate limit → AI classifier. Everything
 * that clears the cheap filters is stored (real or flagged-spam) for the admin
 * inbox; bot hits are dropped silently with a success message so they learn
 * nothing.
 */
final class ContactController extends AbstractController
{
    private const MAX_PER_HOUR = 3;
    private const MIN_FILL_SECONDS = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContactClassifier $classifier,
        private readonly CacheItemPoolInterface $cache,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/kontakt', name: 'contact_submit', methods: ['POST'])]
    public function submit(Request $request): RedirectResponse
    {
        $back = $this->redirectToRoute('page_impressum');

        if (!$this->isCsrfTokenValid('contact', (string) $request->request->get('_token'))) {
            $this->addFlash('contact_error', 'Das Formular ist abgelaufen – bitte erneut senden.');

            return $back;
        }

        // Honeypot + time-trap: a real browser leaves the hidden field empty and
        // needs a few seconds to fill the form. Bots fail one of these → drop
        // silently (pretend success so they don't probe further).
        $ts = (int) $request->request->get('ts', 0);
        $tooFast = $ts <= 0 || ($this->clock->now()->getTimestamp() - $ts) < self::MIN_FILL_SECONDS;
        if (trim((string) $request->request->get('website', '')) !== '' || $tooFast) {
            $this->addFlash('contact_success', 'Danke! Deine Nachricht ist angekommen.');

            return $back;
        }

        // Per-IP rate limit via the app cache (no extra dependency).
        $key = 'contact_rl_'.sha1(($request->getClientIp() ?? '').'|contact');
        $item = $this->cache->getItem($key);
        $count = (int) ($item->get() ?? 0);
        if ($count >= self::MAX_PER_HOUR) {
            $this->addFlash('contact_error', 'Du hast in kurzer Zeit mehrere Nachrichten gesendet. Bitte versuch es später nochmal.');

            return $back;
        }

        $name = trim((string) $request->request->get('name', ''));
        $email = trim((string) $request->request->get('email', ''));
        $message = trim((string) $request->request->get('message', ''));

        if ($name === '' || $message === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('contact_error', 'Bitte Name, eine gültige E-Mail und eine Nachricht angeben.');

            return $back;
        }

        $verdict = $this->classifier->classify($name, mb_substr($email, 0, 180), $message);

        $msg = new ContactMessage(
            mb_substr($name, 0, 120),
            mb_substr($email, 0, 180),
            mb_substr($message, 0, 5000),
            $this->clock->now(),
        );
        $msg->setSpam($verdict['spam'], $verdict['reason'] !== '' ? $verdict['reason'] : null);
        $this->em->persist($msg);
        $this->em->flush();

        $item->set($count + 1)->expiresAfter(3600);
        $this->cache->save($item);

        $this->addFlash('contact_success', 'Danke! Deine Nachricht ist angekommen – ich melde mich.');

        return $back;
    }
}
