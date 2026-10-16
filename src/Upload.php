<?php
/**
 * 업로드 파일 검증·저장
 *
 * - 확장자(허용 목록 + 차단 목록, 이중 확장자 포함)와 실제 MIME(finfo) 이중 검증
 * - 이미지는 getimagesize 로 한 번 더 확인
 * - 저장명은 랜덤 영문(원본 파일명은 DB에만), 위치는 storage/uploads/YYYYMM/
 * - DB의 file_path 는 storage/uploads 기준 상대 경로('202610/abcd….jpg')
 */
final class Upload
{
    public const KIND_IMAGE = 'image';
    public const KIND_ATTACHMENT = 'attachment';

    /** 확장자별 허용 MIME(finfo 결과) */
    private const MIME_MAP = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        // OLE 기반 바이너리 문서: finfo 판별 결과가 환경마다 달라 범위를 넓게 둔다
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage', 'application/octet-stream'],
        'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage', 'application/octet-stream'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage', 'application/octet-stream'],
        'hwp'  => ['application/x-hwp', 'application/haansofthwp', 'application/vnd.hancom.hwp', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage', 'application/octet-stream'],
        // ZIP 기반 문서
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'hwpx' => ['application/hwp+zip', 'application/haansofthwpx', 'application/vnd.hancom.hwpx', 'application/zip', 'application/octet-stream'],
        'zip'  => ['application/zip', 'application/x-zip-compressed', 'application/x-zip'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'],
    ];

    private const IMAGE_MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    ];

    private const ORIGINAL_NAME_MAX = 200;

    /**
     * $_FILES[$field] (name="field[]" 다중 업로드)를 목록으로 정리. 선택 안 한 칸은 건너뛴다.
     * @return list<array{name:string,tmp_name:string,size:int,error:int}>
     */
    public static function collect(string $field): array
    {
        $f = $_FILES[$field] ?? null;
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        $list = [];
        $names = (array) $f['name'];
        foreach (array_keys($names) as $i) {
            $error = (int) (((array) $f['error'])[$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $list[] = [
                'name'     => (string) ($names[$i] ?? ''),
                'tmp_name' => (string) (((array) $f['tmp_name'])[$i] ?? ''),
                'size'     => (int) (((array) $f['size'])[$i] ?? 0),
                'error'    => $error,
            ];
        }
        return $list;
    }

    /**
     * 파일 목록 검증. 하나라도 문제가 있으면 ValidationException.
     * @return list<array{original_name:string,tmp_name:string,size:int,ext:string,mime:string}>
     */
    public static function validate(array $files, string $kind): array
    {
        $isImage = $kind === self::KIND_IMAGE;
        $label = $isImage ? '이미지' : '첨부파일';
        $maxCount = Config::int($isImage ? 'INLINE_IMAGE_MAX' : 'ATTACH_MAX_COUNT');
        $maxBytes = Config::int($isImage ? 'IMAGE_MAX_BYTES' : 'ATTACH_MAX_BYTES');
        $allowed = array_map('strtolower', (array) Config::get($isImage ? 'IMAGE_EXTENSIONS' : 'ATTACH_EXTENSIONS', []));

        if (count($files) > $maxCount) {
            throw new ValidationException(($isImage ? '이미지는' : '첨부파일은') . " 최대 {$maxCount}개까지 올릴 수 있습니다.");
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $result = [];
        foreach ($files as $file) {
            $rawName = $file['name'];
            if (str_contains($rawName, "\0")) {
                throw new ValidationException("{$label} 파일 이름이 올바르지 않습니다.");
            }
            $name = self::cleanName($rawName);
            $shown = '"' . $name . '"';

            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new ValidationException("{$shown}: 파일이 너무 큽니다(최대 " . self::formatBytes($maxBytes) . ').');
            }
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new ValidationException("{$shown}: 업로드에 실패했습니다. 다시 시도하세요.");
            }

            $ext = self::checkExtension($name, $allowed, $shown);

            $size = (int) filesize($file['tmp_name']);
            if ($size <= 0) {
                throw new ValidationException("{$shown}: 빈 파일은 올릴 수 없습니다.");
            }
            if ($size > $maxBytes) {
                throw new ValidationException("{$shown}: 파일이 너무 큽니다(최대 " . self::formatBytes($maxBytes) . ').');
            }

            $mime = strtolower((string) $finfo->file($file['tmp_name']));
            if (!in_array($mime, self::MIME_MAP[$ext] ?? [], true)) {
                throw new ValidationException("{$shown}: 파일 내용이 확장자({$ext})와 맞지 않습니다.");
            }
            if ($isImage) {
                $info = @getimagesize($file['tmp_name']);
                if ($info === false || ($info['mime'] ?? '') !== self::IMAGE_MIME[$ext]) {
                    throw new ValidationException("{$shown}: 올바른 이미지 파일이 아닙니다.");
                }
            }

            $result[] = [
                'original_name' => $name,
                'tmp_name'      => $file['tmp_name'],
                'size'          => $size,
                'ext'           => $ext,
                'mime'          => $mime,
            ];
        }
        return $result;
    }

    /**
     * 검증된 파일을 저장소로 옮기고 상대 경로를 반환한다.
     */
    public static function store(array $validated): string
    {
        $sub = Time::nowObj()->format('Ym');
        $dir = UPLOAD_DIR . DIRECTORY_SEPARATOR . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('업로드 폴더를 만들 수 없습니다.');
        }
        $fileName = bin2hex(random_bytes(16)) . '.' . $validated['ext'];
        if (!move_uploaded_file($validated['tmp_name'], $dir . DIRECTORY_SEPARATOR . $fileName)) {
            throw new RuntimeException('업로드 파일을 저장할 수 없습니다.');
        }
        return $sub . '/' . $fileName;
    }

    /**
     * DB의 상대 경로를 실제 파일 경로로 바꾼다. storage/uploads 밖이거나 없으면 null.
     */
    public static function absolutePath(string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..')) {
            return null;
        }
        $base = realpath(UPLOAD_DIR);
        $path = realpath(UPLOAD_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($base === false || $path === false || !is_file($path)) {
            return null;
        }
        return str_starts_with(strtolower($path), strtolower($base . DIRECTORY_SEPARATOR)) ? $path : null;
    }

    /** 저장된 파일 삭제(정리·롤백용). 성공 또는 이미 없음이면 true */
    public static function remove(string $relative): bool
    {
        $path = self::absolutePath($relative);
        return $path === null || @unlink($path);
    }

    /** 이미지 확장자 → Content-Type */
    public static function imageMime(string $relative): string
    {
        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        return self::IMAGE_MIME[$ext] ?? 'application/octet-stream';
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1), '0'), '.') . 'MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . 'KB';
        }
        return $bytes . 'B';
    }

    /** 경로 구성 요소·제어 문자 제거, 길이 제한 */
    private static function cleanName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = Input::clean($name);
        $name = rtrim($name, ". \t");   // Windows 에서 무시되는 끝 점·공백 제거(a.php. 우회 방지)
        if ($name === '') {
            $name = 'file';
        }
        if (mb_strlen($name) > self::ORIGINAL_NAME_MAX) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr($name, 0, self::ORIGINAL_NAME_MAX - mb_strlen($ext) - 1) . '.' . $ext;
        }
        return $name;
    }

    /** 확장자 검사: 이름의 모든 확장자 조각을 차단 목록과 대조, 마지막 확장자는 허용 목록과 대조 */
    private static function checkExtension(string $name, array $allowed, string $shown): string
    {
        $parts = explode('.', strtolower($name));
        if (count($parts) < 2) {
            throw new ValidationException("{$shown}: 확장자가 없는 파일은 올릴 수 없습니다.");
        }
        $blocked = array_map('strtolower', (array) Config::get('BLOCKED_EXTENSIONS', []));
        foreach (array_slice($parts, 1) as $part) {
            if (in_array(trim($part), $blocked, true)) {
                throw new ValidationException("{$shown}: 실행 파일이나 스크립트는 올릴 수 없습니다.");
            }
        }
        $ext = trim((string) end($parts));
        if (!in_array($ext, $allowed, true)) {
            throw new ValidationException("{$shown}: 허용되지 않는 형식입니다(" . implode(', ', $allowed) . ').');
        }
        return $ext;
    }
}
