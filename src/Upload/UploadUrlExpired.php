<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Upload;

use RuntimeException;

/**
 * @internal signals that the upload URL has to be re-initialised
 */
final class UploadUrlExpired extends RuntimeException {}
