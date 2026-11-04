<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Upload;

use RuntimeException;

/**
 * @internal signals that the upload URL has to be re-initialised
 */
final class UploadUrlExpired extends RuntimeException {}
