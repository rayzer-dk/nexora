<?php

declare(strict_types=1);
namespace Commerce\Core\Health;
enum RequirementLevel: string { case Required='required'; case Recommended='recommended'; case Info='info'; }
