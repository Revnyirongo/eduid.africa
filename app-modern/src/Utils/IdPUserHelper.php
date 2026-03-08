<?php

namespace App\Utils;

use App\Entity\IdPUser;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * IdPUserHelper.
 */
class IdPUserHelper
{
    public function __construct()
    {
        # code...
    }

    public static function sendPasswordResetToken(
        IdPUser $idpuser,
        string $token,
        UrlGeneratorInterface $router,
        Environment $twig,
        MailerInterface $mailer,
        string $fromaddress,
        string $template
    )
    {
        $url = $router->generate(
            'idpuser_reset_password',
            array('token' => $token),
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $body = $twig->render('idp_user/' . $template . '.txt.twig', array('idpuser' => $idpuser, 'url' => $url));

        $message = (new Email())
            ->subject('Password token')
            ->to($idpuser->getEmail())
            ->from($fromaddress)
            ->text($body);

        $mailer->send($message);
    }
}
