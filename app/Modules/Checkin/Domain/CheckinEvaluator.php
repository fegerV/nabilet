<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Domain;

/**
 * Check-in evaluator: real implementation lives in Tickets\Domain.
 * This file redirects to Nabilet\Modules\Tickets\Domain\CheckinEvaluator.
 */
class_alias(\Nabilet\Modules\Tickets\Domain\CheckinEvaluator::class, CheckinEvaluator::class);

if (false) {
    // Just for IDE support
    class CheckinEvaluator extends \Nabilet\Modules\Tickets\Domain\CheckinEvaluator {}
}