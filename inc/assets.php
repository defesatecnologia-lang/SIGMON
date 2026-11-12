<?php
/**
 * Helper para carregar assets compilados pelo Vite.
 * Lê o manifest.json gerado na build e retorna os caminhos com hash.
 */

function vite_manifest(): array
{
    static $manifest = null;
    if ($manifest !== null) {
        return $manifest;
    }

    $manifestPath = __DIR__ . '/../dist/.vite/manifest.json';
    if (!file_exists($manifestPath)) {
        throw new RuntimeException(
            'Manifest do Vite nao encontrado em dist/.vite/manifest.json. '
            . 'Execute "npm run build" antes de acessar o sistema.'
        );
    }

    $content = file_get_contents($manifestPath);
    if ($content === false) {
        throw new RuntimeException('Falha ao ler dist/.vite/manifest.json.');
    }

    $manifest = json_decode($content, true);
    if (!is_array($manifest)) {
        throw new RuntimeException('Manifest do Vite invalido em dist/.vite/manifest.json.');
    }
    return $manifest;
}

/**
 * Versao curta para cache-busting de um asset publicado.
 *
 * Os nomes com hash do Vite ja mudam quando o conteudo muda, mas o navegador
 * pode manter em cache um 301/404 antigo para a mesma URL (aconteceu durante o
 * periodo em que o Nginx respondia 301 em tudo, antes do modo HTTP-only). Como
 * o hash nao muda se o CSS nao mudou, a URL antiga continuava "envenenada".
 *
 * Acrescentar ?v=<mtime> cria uma URL nova a cada build, garantindo que o
 * navegador busque o arquivo de verdade. O Nginx ignora a query em try_files.
 */
function vite_asset_version(string $assetPath): string
{
    $clean = ltrim($assetPath, '/');
    $candidates = [
        __DIR__ . '/../public/' . $clean,
        __DIR__ . '/../dist/' . $clean,
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            return '?v=' . filemtime($file);
        }
    }
    return '';
}

function vite_asset(string $entryName): ?string
{
    $manifest = vite_manifest();

    foreach ($manifest as $entry) {
        if (!empty($entry['isEntry']) && ($entry['name'] ?? '') === $entryName) {
            return $entry['file'];
        }
    }

    return null;
}

function vite_css(): ?string
{
    $manifest = vite_manifest();
    foreach ($manifest as $key => $entry) {
        if ($key === 'index.html' && !empty($entry['css'])) {
            return $entry['css'][0];
        }
    }
    return null;
}

function vite_page_css(string $entryName): void
{
    $manifest = vite_manifest();
    foreach ($manifest as $entry) {
        if (!empty($entry['isEntry']) && ($entry['name'] ?? '') === $entryName && !empty($entry['css'])) {
            foreach ($entry['css'] as $css) {
                echo '<link rel="stylesheet" href="' . htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') . '/' . $css . vite_asset_version($css) . '">' . "\n";
            }
            return;
        }
    }
}

function vite_entry_imports(string $entryName): array
{
    $manifest = vite_manifest();

    $imports = [];
    foreach ($manifest as $entry) {
        if (!empty($entry['isEntry']) && ($entry['name'] ?? '') === $entryName) {
            if (!empty($entry['imports'])) {
                foreach ($entry['imports'] as $impKey) {
                    if (isset($manifest[$impKey])) {
                        $imports[] = $manifest[$impKey]['file'];
                    }
                }
            }
            break;
        }
    }
    return $imports;
}

function vite_page_js(string $entryName): void
{
    $vitePageJs = vite_asset($entryName);
    $viteImports = vite_entry_imports($entryName);
    if ($vitePageJs) :
        foreach ($viteImports as $imp) : ?>
<link rel="modulepreload" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/<?= $imp ?><?= vite_asset_version($imp) ?>">
        <?php endforeach; ?>
<script type="module" src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/<?= $vitePageJs ?><?= vite_asset_version($vitePageJs) ?>"></script>
    <?php endif;
}

/**
 * Retorna o CSS de uma entrada do manifest.
 *
 * Entradas puramente CSS (ex.: bootstrap.scss, fontawesome.scss) têm o próprio
 * arquivo .css no campo `file`, sem o array `css`. Já entradas JS expõem o CSS
 * extraído em `css`. Esta função cobre os dois formatos — em versões recentes
 * do Vite o campo `css` não existe para entradas CSS-only.
 */
function vite_entry_css(string $entryName): ?string
{
    $manifest = vite_manifest();
    foreach ($manifest as $entry) {
        if (empty($entry['isEntry']) || ($entry['name'] ?? '') !== $entryName) {
            continue;
        }
        if (!empty($entry['css'][0])) {
            return (string)$entry['css'][0];
        }
        $file = (string)($entry['file'] ?? '');
        if ($file !== '' && str_ends_with(strtolower($file), '.css')) {
            return $file;
        }
        return null;
    }
    return null;
}

function vite_bootstrap_css(): ?string
{
    return vite_entry_css('bootstrap');
}

function vite_fontawesome_css(): ?string
{
    return vite_entry_css('fontawesome');
}

function vite_bootstrap_js(): ?string
{
    $manifest = vite_manifest();
    foreach ($manifest as $entry) {
        if (!empty($entry['isEntry']) && ($entry['name'] ?? '') === 'bootstrap-js') {
            return $entry['file'];
        }
    }
    return null;
}
