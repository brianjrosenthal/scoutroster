<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/S3Client.php';

/**
 * App-level policy for the Cloudflare R2 bucket that holds event photos and
 * slideshow music: configuration, what may be uploaded, how the browser gets
 * permission to upload, and how pages read objects back.
 *
 * Bytes never pass through this server. The browser asks a presign endpoint
 * for a presigned PUT URL (signed here with the secret key, which never
 * leaves the server), PUTs the file straight to the bucket, and an attach
 * endpoint then calls verifyUploadedObject() before a row is recorded.
 *
 * The bucket stays PRIVATE. Pages embed presigned GET URLs whose signature
 * timestamp is quantized to a window, so every viewer in that window gets a
 * byte-identical URL and the browser can cache the image; the TTL is always
 * at least twice the window so a URL minted at the start of a window outlives
 * its end.
 *
 * Config (config.local.php): R2_ENDPOINT, R2_ACCESS_KEY, R2_SECRET_KEY,
 * R2_PHOTO_BUCKET; optional R2_REGION, PHOTO_MAX_BYTES, AUDIO_MAX_BYTES,
 * PHOTO_URL_WINDOW_SECONDS, PHOTO_URL_TTL_SECONDS, PHOTO_CORS_EXTRA_ORIGINS.
 */
final class PhotoStorage {

  /** How long a presigned upload URL stays valid (the PUT only has to start within it). */
  public const UPLOAD_URL_TTL = 900;

  /** Long edge, in pixels, of the renditions the browser produces. */
  public const DISPLAY_LONG_EDGE = 2048;
  public const THUMB_LONG_EDGE = 400;

  /** Sanity caps on the browser-made JPEG renditions. */
  public const RENDITION_MAX_BYTES = ['display' => 8388608, 'thumb' => 1048576];

  private const DEFAULT_REGION = 'auto';
  private const DEFAULT_BUCKET = 'pack440-photos';
  private const DEFAULT_PHOTO_MAX_BYTES = 26214400;   // 25 MB
  private const DEFAULT_AUDIO_MAX_BYTES = 31457280;   // 30 MB
  private const DEFAULT_URL_WINDOW = 21600;           // 6 h
  private const DEFAULT_URL_TTL = 86400;              // 24 h
  private const MAX_PRESIGN_TTL = 604800;             // 7 days (S3 ceiling)

  /** MIME type => [extension, kind]. Add a row here to accept a new type. */
  private const CONTENT_TYPES = [
    'image/jpeg'  => ['jpg',  'image'],
    'image/png'   => ['png',  'image'],
    'image/webp'  => ['webp', 'image'],
    'image/gif'   => ['gif',  'image'],
    'audio/mpeg'  => ['mp3',  'audio'],
    'audio/mp4'   => ['m4a',  'audio'],
    'audio/aac'   => ['aac',  'audio'],
    'audio/wav'   => ['wav',  'audio'],
  ];

  /** Aliases folded into the canonical types above. */
  private const CONTENT_TYPE_ALIASES = [
    'image/jpg'   => 'image/jpeg',
    'image/pjpeg' => 'image/jpeg',
    'audio/x-m4a' => 'audio/mp4',
    'audio/m4a'   => 'audio/mp4',
    'audio/mp3'   => 'audio/mpeg',
    'audio/x-wav' => 'audio/wav',
    'audio/wave'  => 'audio/wav',
  ];

  private static ?S3Client $client = null;

  // ---------------------------------------------------------------------------
  // Configuration
  // ---------------------------------------------------------------------------

  private static function config(string $name, string $default = ''): string {
    return defined($name) ? trim((string)constant($name)) : $default;
  }

  /**
   * The endpoint with no bucket in it. Cloudflare's bucket settings page shows
   * the "S3 API" value with the bucket appended; pasting that is fine because
   * the suffix is stripped here.
   */
  public static function endpoint(): string {
    $endpoint = rtrim(self::config('R2_ENDPOINT'), '/');
    $bucket = self::bucket();
    if ($bucket !== '' && str_ends_with($endpoint, '/' . $bucket)) {
      $endpoint = substr($endpoint, 0, -strlen('/' . $bucket));
    }
    return $endpoint;
  }

  public static function region(): string {
    return self::config('R2_REGION', self::DEFAULT_REGION);
  }

  /** The bucket name; R2_PHOTO_BUCKET overrides the default "pack440-photos". */
  public static function bucket(): string {
    return self::config('R2_PHOTO_BUCKET', self::DEFAULT_BUCKET);
  }

  /** Which of the required constants are missing or empty. */
  public static function missingConfig(): array {
    $missing = [];
    foreach (['R2_ENDPOINT', 'R2_ACCESS_KEY', 'R2_SECRET_KEY'] as $name) {
      if (self::config($name) === '') $missing[] = $name;
    }
    if (self::bucket() === '') $missing[] = 'R2_PHOTO_BUCKET';
    return $missing;
  }

  /**
   * Whether uploads can work. Upload UI hides itself when this is false, so an
   * environment with no storage credentials degrades quietly.
   */
  public static function isConfigured(): bool {
    return self::missingConfig() === [];
  }

  /** The storage client, with an injection seam for tests. */
  public static function storage(?S3Client $inject = null): S3Client {
    if ($inject !== null) {
      self::$client = $inject;
    }
    if (self::$client === null) {
      self::$client = new S3Client(
        self::endpoint(),
        self::region(),
        self::config('R2_ACCESS_KEY'),
        self::config('R2_SECRET_KEY')
      );
    }
    return self::$client;
  }

  public static function resetStorage(): void {
    self::$client = null;
  }

  // ---------------------------------------------------------------------------
  // Content types and limits
  // ---------------------------------------------------------------------------

  /** "image/JPEG;q=1" -> "image/jpeg"; aliases folded. */
  public static function normalizeContentType(string $contentType): string {
    $base = strtolower(trim(explode(';', $contentType, 2)[0]));
    return self::CONTENT_TYPE_ALIASES[$base] ?? $base;
  }

  /** 'image' | 'audio' | null for an unsupported type. */
  public static function kindOf(string $contentType): ?string {
    return self::CONTENT_TYPES[self::normalizeContentType($contentType)][1] ?? null;
  }

  public static function extensionFor(string $contentType): ?string {
    return self::CONTENT_TYPES[self::normalizeContentType($contentType)][0] ?? null;
  }

  /** @return string[] accepted MIME types, optionally for one kind */
  public static function allowedContentTypes(?string $kind = null): array {
    $out = [];
    foreach (self::CONTENT_TYPES as $type => [$ext, $k]) {
      if ($kind === null || $k === $kind) $out[] = $type;
    }
    return $out;
  }

  /** Extensions (no dot) for a kind, for file-input accept lists and key regexes. */
  public static function extensionsFor(string $kind): array {
    $out = [];
    foreach (self::CONTENT_TYPES as [$ext, $k]) {
      if ($k === $kind && !in_array($ext, $out, true)) $out[] = $ext;
    }
    return $out;
  }

  public static function maxBytes(string $kind = 'image'): int {
    if ($kind === 'audio') {
      $v = defined('AUDIO_MAX_BYTES') ? (int)AUDIO_MAX_BYTES : self::DEFAULT_AUDIO_MAX_BYTES;
    } else {
      $v = defined('PHOTO_MAX_BYTES') ? (int)PHOTO_MAX_BYTES : self::DEFAULT_PHOTO_MAX_BYTES;
    }
    return max(1024 * 1024, $v);
  }

  public static function humanBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $value = (float)$bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
      $value /= 1024;
      $i++;
    }
    return ($i === 0 ? (string)(int)$value : rtrim(rtrim(number_format($value, 1), '0'), '.')) . ' ' . $units[$i];
  }

  // ---------------------------------------------------------------------------
  // Object keys
  // ---------------------------------------------------------------------------

  /** Random 32-hex stem shared by the three renditions of one photo. */
  public static function newStem(): string {
    return bin2hex(random_bytes(16));
  }

  public static function isStem(string $s): bool {
    return preg_match('/^[0-9a-f]{32}$/', $s) === 1;
  }

  /**
   * The three object keys for one photo of an event.
   * @return array{original:string,display:string,thumb:string}
   */
  public static function photoKeys(int $eventId, string $stem, string $originalContentType): array {
    $ext = self::extensionFor($originalContentType);
    if ($ext === null || self::kindOf($originalContentType) !== 'image') {
      throw new InvalidArgumentException('Unsupported image type "' . $originalContentType . '". Please use a JPEG, PNG, WebP or GIF file.');
    }
    if (!self::isStem($stem)) {
      throw new InvalidArgumentException('Malformed upload reference.');
    }
    $prefix = 'photos/' . $eventId . '/' . $stem;
    return [
      'original' => $prefix . '.' . $ext,
      'display'  => $prefix . '_d.jpg',
      'thumb'    => $prefix . '_t.jpg',
    ];
  }

  /** Whether a key has the shape photoKeys() produces for this event. */
  public static function keyBelongsToEvent(string $key, int $eventId): bool {
    $exts = implode('|', array_map('preg_quote', self::extensionsFor('image')));
    return preg_match('#^photos/' . $eventId . '/[0-9a-f]{32}(_d|_t)?\.(' . $exts . ')$#', $key) === 1;
  }

  /** A new key for a music track. Not tied to a slideshow: tracks are reusable. */
  public static function audioKey(string $contentType): string {
    $ext = self::extensionFor($contentType);
    if ($ext === null || self::kindOf($contentType) !== 'audio') {
      throw new InvalidArgumentException('Unsupported audio type "' . $contentType . '". Please use an MP3 or M4A file.');
    }
    return 'audio/' . self::newStem() . '.' . $ext;
  }

  public static function isAudioKey(string $key): bool {
    $exts = implode('|', array_map('preg_quote', self::extensionsFor('audio')));
    return preg_match('#^audio/[0-9a-f]{32}\.(' . $exts . ')$#', $key) === 1;
  }

  // ---------------------------------------------------------------------------
  // Uploads
  // ---------------------------------------------------------------------------

  /**
   * Everything the browser needs to PUT one object: the presigned URL and the
   * headers it must send. Only the host is signed (R2 objects are private
   * regardless, so no ACL header).
   * @return array{url:string,headers:array<string,string>,expires_in:int}
   */
  public static function presignUploadFor(string $key, string $contentType): array {
    $type = self::normalizeContentType($contentType);
    if (self::extensionFor($type) === null) {
      throw new InvalidArgumentException('Unsupported file type "' . $contentType . '".');
    }
    $url = self::storage()->presignedPutUrl(self::bucket(), $key, time(), self::UPLOAD_URL_TTL);
    return [
      'url'        => $url,
      'headers'    => ['Content-Type' => $type],
      'expires_in' => self::UPLOAD_URL_TTL,
    ];
  }

  /**
   * Confirm an object the browser claims to have uploaded: it must exist, be
   * non-empty, be of the expected kind (and exact type when given) and be
   * within the size cap. Anything else is deleted so a tampered client cannot
   * park junk in the bucket.
   * @return array{content_type:string,size:int}
   */
  public static function verifyUploadedObject(string $key, string $expectKind, ?string $expectContentType = null, ?int $maxBytes = null): array {
    $head = self::storage()->headObject(self::bucket(), $key);
    if ($head === null) {
      throw new RuntimeException('The upload did not reach storage. Please try again.');
    }
    $type = self::normalizeContentType((string)$head['content_type']);
    $size = (int)$head['size'];
    $cap = $maxBytes ?? self::maxBytes($expectKind);
    $fail = null;
    if (self::kindOf($type) !== $expectKind) {
      $fail = 'That file is not a supported ' . $expectKind . ' type.';
    } elseif ($expectContentType !== null && $type !== self::normalizeContentType($expectContentType)) {
      $fail = 'The uploaded file type did not match (' . $type . ').';
    } elseif ($size <= 0) {
      $fail = 'The uploaded file is empty.';
    } elseif ($size > $cap) {
      $fail = 'That file is larger than the ' . self::humanBytes($cap) . ' limit.';
    }
    if ($fail !== null) {
      try { self::deleteObjects([$key]); } catch (\Throwable $e) { /* best effort */ }
      throw new RuntimeException($fail);
    }
    return ['content_type' => $type, 'size' => $size];
  }

  /** @param string[] $keys */
  public static function deleteObjects(array $keys): void {
    $keys = array_values(array_filter($keys, static fn($k) => is_string($k) && $k !== ''));
    if ($keys === []) return;
    self::storage()->deleteObjects(self::bucket(), $keys);
  }

  // ---------------------------------------------------------------------------
  // Read URLs (pure local signing, cacheable)
  // ---------------------------------------------------------------------------

  public static function urlWindowSeconds(): int {
    $w = defined('PHOTO_URL_WINDOW_SECONDS') ? (int)PHOTO_URL_WINDOW_SECONDS : self::DEFAULT_URL_WINDOW;
    return min(max(60, $w), intdiv(self::MAX_PRESIGN_TTL, 2));
  }

  public static function urlTtlSeconds(): int {
    $ttl = defined('PHOTO_URL_TTL_SECONDS') ? (int)PHOTO_URL_TTL_SECONDS : self::DEFAULT_URL_TTL;
    return min(max($ttl, self::urlWindowSeconds() * 2), self::MAX_PRESIGN_TTL);
  }

  /** The signature timestamp for read URLs, rounded down to the window. */
  public static function urlIssuedAt(?int $now = null): int {
    $window = self::urlWindowSeconds();
    return intdiv($now ?? time(), $window) * $window;
  }

  /**
   * The URL an <img> or <audio> reads the object from: a presigned GET that is
   * identical for every viewer within the current window (cacheable) and valid
   * for the TTL. No storage round trip. Pass one $now for a whole page so all
   * its URLs share a window.
   */
  public static function urlFor(string $key, ?int $now = null): string {
    if ($key === '') return '';
    return self::storage()->presignedGetUrl(self::bucket(), $key, self::urlIssuedAt($now), self::urlTtlSeconds());
  }

  // ---------------------------------------------------------------------------
  // Setup / diagnostics
  // ---------------------------------------------------------------------------

  /** The origin of the current request, e.g. https://my.scarsdalepack440.org. */
  public static function currentOrigin(): string {
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '') return '';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . $host;
  }

  /**
   * Origins the bucket's CORS rule should allow so browsers can PUT uploads:
   * this site, any extra configured origins, and local development.
   * @return string[]
   */
  public static function corsOrigins(): array {
    $origins = [];
    $cur = self::currentOrigin();
    if ($cur !== '') $origins[] = $cur;
    foreach (explode(',', self::config('PHOTO_CORS_EXTRA_ORIGINS')) as $o) {
      $o = rtrim(trim($o), '/');
      if ($o !== '') $origins[] = $o;
    }
    $origins[] = 'http://localhost:8080';
    $origins[] = 'http://127.0.0.1:8080';
    return array_values(array_unique($origins));
  }

  /**
   * Apply the CORS rule as the UNION of what the bucket already allows and
   * corsOrigins(), so applying from a dev box never drops the production
   * origin. Returns the origins now in force.
   * @return string[]
   */
  public static function applyCors(): array {
    $existing = [];
    try {
      $existing = self::storage()->getBucketCorsOrigins(self::bucket()) ?? [];
    } catch (\Throwable $e) {
      $existing = [];
    }
    $origins = array_values(array_unique(array_merge($existing, self::corsOrigins())));
    self::storage()->putBucketCors(self::bucket(), $origins);
    return $origins;
  }

  /**
   * Diagnostic for Admin -> Photo Storage: perform the presigned PUT exactly as
   * the browser does (same URL, same headers, a tiny JPEG body), then HEAD, read
   * back through a presigned GET and delete. Returns a one-line result including
   * the raw storage error when the PUT fails. Never throws.
   */
  public static function describeTestUpload(): string {
    $key = 'photos/0/' . self::newStem() . '_t.jpg';
    $body = self::tinyJpeg();
    try {
      $grant = self::presignUploadFor($key, 'image/jpeg');
    } catch (\Throwable $e) {
      return 'Could not presign: ' . $e->getMessage();
    }
    $ch = curl_init($grant['url']);
    $headers = [];
    foreach ($grant['headers'] as $name => $value) {
      $headers[] = $name . ': ' . $value;
    }
    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST  => 'PUT',
      CURLOPT_POSTFIELDS     => $body,
      CURLOPT_HTTPHEADER     => $headers,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($resp === false) {
      return 'Presigned PUT failed before a response: ' . $curlError;
    }
    if ($status < 200 || $status >= 300) {
      $detail = trim(strip_tags(preg_replace('/<RequestId>.*?<\/RequestId>|<HostId>.*?<\/HostId>/s', '', (string)$resp) ?? ''));
      return 'Presigned PUT returned HTTP ' . $status . ($detail !== '' ? ': ' . substr($detail, 0, 300) : '')
           . ' (signed headers: ' . implode(', ', array_keys($grant['headers'])) . ')';
    }
    try {
      $head = self::storage()->headObject(self::bucket(), $key);
    } catch (\Throwable $e) {
      return 'Presigned PUT succeeded (HTTP ' . $status . ') but verifying failed: ' . $e->getMessage();
    }

    $ch = curl_init(self::urlFor($key));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30]);
    $read = curl_exec($ch);
    $readStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    try {
      self::deleteObjects([$key]);
    } catch (\Throwable $e) {
      return 'Upload and read-back worked but deleting the test object failed: ' . $e->getMessage();
    }
    if ($read !== $body || $readStatus !== 200) {
      return 'Upload worked (HTTP ' . $status . ') but reading back via a presigned GET returned HTTP ' . $readStatus . '.';
    }
    return 'Test upload succeeded: PUT HTTP ' . $status . ', object seen with ' . (int)($head['size'] ?? 0)
         . ' bytes and type "' . (string)($head['content_type'] ?? '') . '", read back HTTP 200, then deleted.'
         . ' Browser uploads should work if the CORS rule includes this site\'s origin.';
  }

  /** A real 1x1 JPEG (via GD) for the test upload, or a text body if GD is missing. */
  private static function tinyJpeg(): string {
    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) {
      $im = imagecreatetruecolor(1, 1);
      if ($im !== false) {
        imagefill($im, 0, 0, imagecolorallocate($im, 128, 128, 128));
        ob_start();
        imagejpeg($im, null, 80);
        $bytes = (string)ob_get_clean();
        if ($bytes !== '') return $bytes;
      }
    }
    return 'pack440 test upload';
  }
}
