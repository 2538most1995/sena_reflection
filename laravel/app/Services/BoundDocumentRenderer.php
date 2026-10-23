<?php

namespace App\Services;

use App\Exceptions\DocumentRuntimeUnavailableException;
use App\Models\Document;
use RuntimeException;
use Throwable;

class BoundDocumentRenderer
{
    public function __construct(private readonly DocumentService $documents) {}

    /** @param array<string, mixed> $model */
    public function html(array $model, bool $browserAssets = false): string
    {
        try {
            $model = $this->embedImages($model);
            $build = realpath(public_path('build'));
            if (! $build || ! is_file($build.'/manifest.json')) {
                throw new RuntimeException('The document snapshot build manifest is unavailable.');
            }
            $manifest = json_decode(file_get_contents($build.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $entry = 'resources/js/document-snapshot.tsx';
            if (! is_array($manifest[$entry] ?? null)) {
                throw new RuntimeException('The document snapshot entry has not been built.');
            }
            $styles = [];
            if (is_file($build.'/fonts-manifest.json')) {
                $fonts = json_decode(file_get_contents($build.'/fonts-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
                if (is_string($fonts['style']['file'] ?? null)) {
                    $styles[$fonts['style']['file']] = true;
                }
            }
            $visited = [];
            $collect = function (string $key) use (&$collect, &$visited, &$styles, $manifest): void {
                if (isset($visited[$key])) {
                    return;
                }
                $visited[$key] = true;
                $chunk = $manifest[$key] ?? null;
                if (! is_array($chunk)) {
                    throw new RuntimeException('The document snapshot import is unavailable.');
                }
                foreach ($chunk['imports'] ?? [] as $import) {
                    $collect($import);
                }
                foreach ($chunk['css'] ?? [] as $css) {
                    $styles[$css] = true;
                }
            };
            $collect($entry);
            $css = '';
            foreach (array_keys($styles) as $file) {
                $path = $this->buildFile($build, $file);
                $source = file_get_contents($path);
                $source = preg_replace_callback('~url\(\s*([\'"]?)(.*?)\1\s*\)~i', function (array $match) use ($build, $file, $browserAssets): string {
                    $url = trim($match[2]);
                    if (str_starts_with($url, 'data:')) {
                        return $match[0];
                    }
                    $path = parse_url($url, PHP_URL_PATH);
                    if (! is_string($path) || preg_match('~^(?:https?:)?//~i', $url)) {
                        throw new RuntimeException('Remote document stylesheet resources are not supported.');
                    }
                    if (str_starts_with($path, '/')) {
                        $position = strpos($path, '/build/');
                        if ($position === false) {
                            throw new RuntimeException('The document stylesheet asset is outside the build directory.');
                        }
                        $relative = substr($path, $position + 7);
                    } else {
                        $relative = dirname($file).'/'.$path;
                    }
                    $absolute = $this->buildFile($build, rawurldecode($relative));
                    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($build) + 1));

                    return 'url("'.($browserAssets ? $this->webAsset($relative) : $this->fileUrl($absolute)).'")';
                }, $source);
                $css .= "\n".$source;
            }
            $entryFile = $manifest[$entry]['file'] ?? '';
            $entryPath = $this->buildFile($build, $entryFile);
            $script = $browserAssets ? $this->webAsset($entryFile) : $this->fileUrl($entryPath);
            $json = json_encode($model, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
            $title = e((string) ($model['title'] ?? 'พิมพ์เอกสาร'));
            $css = str_ireplace('</style', '<\/style', $css);

            return '<!doctype html><html lang="th" data-document-snapshot-mode="'.($browserAssets ? 'browser' : 'local').'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="sena-document-snapshot" content="1"><title>'.$title.'</title><style>'.$css.'</style></head><body><div id="document-snapshot-root"></div><script id="document-snapshot-data" type="application/json">'.$json.'</script><script type="module" src="'.e($script).'"></script></body></html>';
        } catch (DocumentRuntimeUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DocumentRuntimeUnavailableException($exception);
        }
    }

    /** @param array<string, mixed> $model
     * @return array<string, mixed>
     */
    private function embedImages(array $model): array
    {
        if (! is_string($model['id'] ?? null) || ! is_array($model['content'] ?? null) || ! is_array($model['pageSettings'] ?? null) || ! is_array($model['objects'] ?? null)) {
            throw new RuntimeException('The canonical document snapshot is incomplete.');
        }
        $references = [];
        $collect = function (array $node) use (&$collect, &$references): void {
            if (($node['type'] ?? null) === 'image') {
                $id = $node['attrs']['assetId'] ?? null;
                if (! is_string($id) || $id === '') {
                    throw new RuntimeException('A document image has no private asset identifier.');
                }
                $references[$id] = true;
            }
            foreach ($node['content'] ?? [] as $child) {
                $collect($child);
            }
        };
        $collect($model['content']);
        foreach ($model['objects'] as $object) {
            if (($object['type'] ?? null) === 'image') {
                if (! is_string($object['assetId'] ?? null) || $object['assetId'] === '') {
                    throw new RuntimeException('A document image object has no private asset identifier.');
                }
                $references[$object['assetId']] = true;
            }
            if (is_array($object['content'] ?? null)) {
                $collect($object['content']);
            }
        }
        $images = [];
        if ($references !== []) {
            $document = Document::query()->findOrFail($model['id']);
            $assets = $document->assets()->whereKey(array_keys($references))->get()->keyBy('id');
            foreach (array_keys($references) as $id) {
                $asset = $assets->get($id);
                if (! $asset || ! in_array($asset->mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                    throw new RuntimeException('A private document image is unavailable.');
                }
                $path = $this->documents->assetPath($document, $asset);
                if (filesize($path) > 5 * 1024 * 1024) {
                    throw new RuntimeException('A private document image exceeds the supported size.');
                }
                $images[$id] = 'data:'.$asset->mime.';base64,'.base64_encode(file_get_contents($path));
            }
        }
        $replace = function (array $node) use (&$replace, $images): array {
            if (($node['type'] ?? null) === 'image') {
                $node['attrs']['src'] = $images[$node['attrs']['assetId']];
            }
            if (isset($node['content'])) {
                $node['content'] = array_map($replace, $node['content']);
            }

            return $node;
        };
        $model['content'] = $replace($model['content']);
        foreach ($model['objects'] as &$object) {
            if (($object['type'] ?? null) === 'image') {
                $object['src'] = $images[$object['assetId']];
            }
            if (is_array($object['content'] ?? null)) {
                $object['content'] = $replace($object['content']);
            }
        }
        unset($object);
        $model['assets'] = [];

        return $model;
    }

    private function buildFile(string $root, string $file): string
    {
        $path = realpath($root.'/'.$file);
        if (! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw new RuntimeException('The document build asset is unavailable.');
        }

        return $path;
    }

    private function fileUrl(string $path): string
    {
        return 'file://'.implode('/', array_map('rawurlencode', explode('/', str_replace(DIRECTORY_SEPARATOR, '/', $path))));
    }

    private function webAsset(string $file): string
    {
        return rtrim(url('/'), '/').'/build/'.implode('/', array_map('rawurlencode', explode('/', $file)));
    }
}
