<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\ContactClassifier;
use App\Entity\ContactMessage;
use App\Service\ContactFormToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public contact form handler. Layered, cheap-to-expensive spam defence:
 * signed form token (incl. time-trap) → honeypot → field validation → per-IP
 * rate limit → AI classifier. Everything that clears the cheap filters is
 * stored (real or flagged-spam) for the admin inbox; bot hits are dropped
 * silently with a success message so they learn nothing.
 *
 * Stateless on purpose: neither a session CSRF token nor flash messages, as
 * both would set a session cookie and the privacy notice promises that public
 * pages set none. The outcome travels back as a query parameter which
 * impressum.html.twig turns into the message.
 */
final class ContactController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContactClassifier $classifier,
        private readonly ContactFormToken $formToken,
        private readonly RateLimiterFactoryInterface $contactFormLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/kontakt', name: 'contact_submit', methods: ['POST'])]
    public function submit(Request $request): RedirectResponse
    {
        $ts = (int) $request->request->get('ts', 0);

        // Honeypot + time-trap: a real browser leaves the hidden field empty and
        // needs a few seconds to fill the form. Bots fail one of these → drop
        // silently (pretend success so they don't probe further).
        if (trim((string) $request->request->get('website', '')) !== '' || $this->formToken->isTooFresh($ts)) {
            return $this->backWith('ok');
        }
        if (!$this->formToken->isValid($ts, (string) $request->request->get('_token', ''))) {
            return $this->backWith('abgelaufen');
        }

        // Cap the fields once, up front — classifier prompt (token cost) and
        // stored record use the same values; the textarea maxlength is
        // client-side only.
        $name = mb_substr(trim((string) $request->request->get('name', '')), 0, 120);
        $email = mb_substr(trim((string) $request->request->get('email', '')), 0, 180);
        $message = mb_substr(trim((string) $request->request->get('message', '')), 0, 5000);

        if ($name === '' || $message === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->backWith('unvollstaendig');
        }

        // Per-IP limit (config: framework.rate_limiter.contact_form), consumed
        // before the paid classifier call so a flood costs no tokens and after
        // validation so typos don't eat the allowance. Hashed key: the limiter
        // store must not hold raw IPs.
        $limit = $this->contactFormLimiter->create(sha1(($request->getClientIp() ?? '').'|contact'))->consume();
        if (!$limit->isAccepted()) {
            return $this->backWith('limit');
        }

        $verdict = $this->classifier->classify($name, $email, $message);

        $msg = new ContactMessage($name, $email, $message, $this->clock->now());
        $msg->setSpam($verdict['spam'], $verdict['reason'] !== '' ? $verdict['reason'] : null);
        $this->em->persist($msg);
        $this->em->flush();

        return $this->backWith('ok');
    }

    /** Back to the form; the template maps the status key to the message shown. */
    private function backWith(string $status): RedirectResponse
    {
        return $this->redirectToRoute('page_impressum', ['kontakt' => $status, '_fragment' => 'kontakt']);
    }
}
