<?php

declare(strict_types=1);

namespace Workflow;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Webhook
{
}
