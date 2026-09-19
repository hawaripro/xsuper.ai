<?php

return [
    // Block registrations from throwaway/temporary inbox providers.
    'block_disposable' => (bool) env('EMAIL_BLOCK_DISPOSABLE', true),

    // MX hostnames that identify a Google-hosted domain (Gmail or Google Workspace).
    'google_mx' => [
        'aspmx.l.google.com',
        'alt1.aspmx.l.google.com',
        'alt2.aspmx.l.google.com',
        'alt3.aspmx.l.google.com',
        'alt4.aspmx.l.google.com',
        'googlemail.com',
        'google.com',
    ],

    // Consumer Google inboxes are Gmail, not Workspace.
    'gmail_domains' => ['gmail.com', 'googlemail.com'],

    // Curated set of disposable / temporary email domains. Extend via config as needed.
    'disposable_domains' => [
        '0-mail.com', '0clickemail.com', '10minutemail.com', '10minutemail.net', '20minutemail.com',
        '33mail.com', 'anonbox.net', 'anonymbox.com', 'armyspy.com', 'binkmail.com', 'bobmail.info',
        'bugmenot.com', 'burnermail.io', 'byom.de', 'cuvox.de', 'dayrep.com', 'deadaddress.com',
        'despam.it', 'dispostable.com', 'dodgeit.com', 'dodgit.com', 'drdrb.net', 'dumpmail.de',
        'e4ward.com', 'einrot.com', 'emailondeck.com', 'emailsensei.com', 'emailtemporario.com.br',
        'emltmp.com', 'fakeinbox.com', 'fakemail.net', 'fakemailgenerator.com', 'fastmailbox.net',
        'filzmail.com', 'fleckens.hu', 'getairmail.com', 'getnada.com', 'ghosttexter.de',
        'grr.la', 'guerrillamail.biz', 'guerrillamail.com', 'guerrillamail.de', 'guerrillamail.info',
        'guerrillamail.net', 'guerrillamail.org', 'guerrillamailblock.com', 'harakirimail.com',
        'hidemail.de', 'inboxalias.com', 'inboxbear.com', 'incognitomail.org', 'jetable.org',
        'kasmail.com', 'klzlk.com', 'koszmail.pl', 'letthemeatspam.com', 'mailcatch.com',
        'maildrop.cc', 'maileater.com', 'mailexpire.com', 'mailforspam.com', 'mailimate.com',
        'mailinator.com', 'mailinator.net', 'mailinator2.com', 'mailmetrash.com', 'mailmoat.com',
        'mailnesia.com', 'mailnull.com', 'mailquack.com', 'mailsac.com', 'mailslurp.com',
        'mailtemp.info', 'mailtothis.com', 'meltmail.com', 'mintemail.com', 'moakt.com',
        'mohmal.com', 'mt2015.com', 'mytemp.email', 'mytrashmail.com', 'nada.email', 'no-spam.ws',
        'nomail.xl.cx', 'nospam.ze.tc', 'nospamfor.us', 'notmailinator.com', 'nowmymail.com',
        'objectmail.com', 'obobbo.com', 'onewaymail.com', 'oneoffemail.com', 'opayq.com',
        'proxymail.eu', 'rcpt.at', 'reallymymail.com', 'rhyta.com', 'safetymail.info',
        'sharklasers.com', 'shieldedmail.com', 'shortmail.net', 'sneakemail.com', 'sofimail.com',
        'sogetthis.com', 'spam4.me', 'spamavert.com', 'spambog.com', 'spambox.us', 'spamfree24.org',
        'spamgourmet.com', 'spamherelots.com', 'spamhole.com', 'spamify.com', 'spamspot.com',
        'spamthis.co.uk', 'spamtrail.com', 'superrito.com', 'tafmail.com', 'teleworm.us',
        'temp-mail.io', 'temp-mail.org', 'tempail.com', 'tempemail.com', 'tempemail.net',
        'tempinbox.com', 'tempmail.com', 'tempmail.net', 'tempmail.plus', 'tempmailaddress.com',
        'tempmailo.com', 'tempomail.fr', 'temporarily.de', 'temporaryemail.net', 'temporaryinbox.com',
        'thankyou2010.com', 'throwawaymail.com', 'tmail.ws', 'tmailinator.com', 'trash-mail.at',
        'trash-mail.com', 'trash-mail.de', 'trash2009.com', 'trashmail.at', 'trashmail.com',
        'trashmail.de', 'trashmail.me', 'trashmail.net', 'trashmail.org', 'trashymail.com',
        'tyldd.com', 'vpn.st', 'vsimcard.com', 'wegwerfmail.de', 'wegwerfmail.net', 'wegwerfmail.org',
        'wh4f.org', 'willhackforfood.biz', 'willselfdestruct.com', 'xagloo.com', 'yopmail.com',
        'yopmail.fr', 'yopmail.net', 'youmailr.com', 'yuurok.com', 'zetmail.com', '10minutemail.co.uk',
        'emailfake.com', 'tmpmail.org', 'tmpmail.net', 'luxusmail.org', 'maildim.com', '1secmail.com',
        '1secmail.org', '1secmail.net', 'inboxkitten.com', 'mailpoof.com', 'dropmail.me', 'minuteinbox.com',
    ],
];
