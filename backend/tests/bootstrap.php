<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// The generated file guarded this with method_exists(), which has been true
// since Symfony 5. Kept as a plain call: a condition that can never be false
// hides nothing and only invites the reader to wonder when it might be.
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
