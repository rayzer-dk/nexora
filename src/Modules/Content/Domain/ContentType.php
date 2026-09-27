<?php

declare(strict_types=1);
namespace Commerce\Modules\Content\Domain;
enum ContentType: string { case Page='page'; case Article='article'; case Landing='landing'; }
