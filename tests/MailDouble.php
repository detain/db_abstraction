<?php
/**
 * A stand-in for \MyAdmin\Mail, so the tests that reach Generic::emailError()/haltmsg() can never send
 * real email when the core application's autoloader is the one in use (it would otherwise load the real
 * class, whose failsafeMail() speaks SMTP). Required only while the real class is not loaded yet.
 */

namespace MyAdmin {
	if (!class_exists('MyAdmin\\Mail', false)) {
		class Mail
		{
			const IS_TEST_DOUBLE = true;

			/** @var array[] every call made: [method, subject] */
			public static $sent = [];

			public static function failsafeMail(...$args)
			{
				self::$sent[] = ['failsafeMail', isset($args[0]) ? (string) $args[0] : ''];
				return true;
			}

			public function adminMail(...$args)
			{
				self::$sent[] = ['adminMail', isset($args[0]) ? (string) $args[0] : ''];
				return true;
			}
		}
	}
}
