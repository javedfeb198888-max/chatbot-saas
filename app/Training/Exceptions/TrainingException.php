<?php

namespace App\Training\Exceptions;

use RuntimeException;

/**
 * A problem the client can understand and fix (bad URL, page not found...).
 * Its message is shown on the Training page.
 */
class TrainingException extends RuntimeException
{
}
