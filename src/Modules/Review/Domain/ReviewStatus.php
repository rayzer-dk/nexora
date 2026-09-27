<?php

declare(strict_types=1);
namespace Commerce\Modules\Review\Domain;
enum ReviewStatus: string { case Pending='pending'; case Published='published'; case Rejected='rejected'; case Spam='spam'; }
