<?php

declare(strict_types=1);
namespace Commerce\Modules\Content\Domain;
enum ContentStatus: string { case Draft='draft'; case Scheduled='scheduled'; case Published='published'; case Archived='archived'; }
