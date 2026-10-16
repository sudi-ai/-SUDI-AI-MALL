<?php
namespace app\services\user;

/** Only use when delivery definitely did not happen; never for a timeout. */
class SmsSendRejectedException extends \RuntimeException
{
}
