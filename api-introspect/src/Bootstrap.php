<?php

/**
 * Bootstrap the OPNsense MVC framework from a checkout living *outside* this
 * tool (the `opnsense` git submodule sitting beside this directory).
 *
 * This mirrors `src/opnsense/www/api.php`: include the framework's own
 * `config.php` (which returns an OPNsense\Core\AppConfig) and then `loader.php`
 * (which registers the autoloader using `$config`). We never route a request —
 * we only want classes autoloadable and config readable.
 */

namespace OpnsenseApiIntrospect;

use OPNsense\Core\AppConfig;

final class Bootstrap
{
    /** Absolute path to the opnsense source checkout root (the submodule). */
    private string $opnsenseRoot;

    private ?AppConfig $config = null;

    public function __construct(string $opnsenseRoot)
    {
        $real = realpath($opnsenseRoot);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException("opnsense root not found: {$opnsenseRoot}");
        }
        $this->opnsenseRoot = $real;
    }

    /**
     * Resolve the opnsense submodule location. Precedence:
     *   1. explicit --opnsense-root CLI arg / passed path
     *   2. OPNSENSE_ROOT environment variable
     *   3. the `opnsense` directory beside this tool (default submodule layout)
     */
    public static function locate(?string $override = null): self
    {
        $candidates = array_filter([
            $override,
            getenv('OPNSENSE_ROOT') ?: null,
            dirname(__DIR__, 2) . '/opnsense',
        ]);

        foreach ($candidates as $candidate) {
            $mvc = $candidate . '/src/opnsense/mvc/app/config/config.php';
            if (is_file($mvc)) {
                return new self($candidate);
            }
        }

        throw new \RuntimeException(
            "Could not locate an opnsense checkout. Tried: " . implode(', ', $candidates) .
            "\nPass --opnsense-root <path>, set \$OPNSENSE_ROOT, or run " .
            "`git submodule update --init` to populate ./opnsense."
        );
    }

    public function root(): string
    {
        return $this->opnsenseRoot;
    }

    private function configDir(): string
    {
        return $this->opnsenseRoot . '/src/opnsense/mvc/app/config';
    }

    /**
     * Include the framework's config.php + loader.php exactly as api.php does,
     * registering the OPNsense autoloader. Idempotent.
     */
    public function boot(): AppConfig
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $configPhp = $this->configDir() . '/config.php';
        $loaderPhp = $this->configDir() . '/loader.php';

        // On a real box, bundled libraries under contrib/ (base32, phpseclib,
        // etc.) are deployed onto PHP's include_path; several controllers do a
        // bare `require_once 'base32/Base32.php'`. Replicate that here.
        $contrib = $this->opnsenseRoot . '/contrib';
        if (is_dir($contrib)) {
            set_include_path($contrib . PATH_SEPARATOR . get_include_path());
        }

        // config.php does a bare `require_once 'AppConfig.php'`; PHP resolves
        // that against the calling script's own directory, so an absolute
        // include path here is enough regardless of our CWD.
        /** @var AppConfig $config */
        $config = include $configPhp;

        if (!$config instanceof AppConfig) {
            throw new \RuntimeException("config.php did not return an AppConfig instance");
        }

        // loader.php expects `$config` in scope.
        include $loaderPhp;

        // Point the framework at a throwaway sample config so models can
        // instantiate (and thus resolve their field tree) without a live
        // /conf/config.xml. The sample carries no rows, so we get the schema
        // with empty collections — exactly what introspection wants.
        $sampleConf = dirname(__DIR__) . '/fixtures/conf';
        if (is_file($sampleConf . '/config.xml')) {
            $config->update('application.configDir', $sampleConf);
            $config->update('application.configDefault', $sampleConf . '/config.xml');
        }

        // Redirect cache/temp to writable, tool-local dirs (the defaults under
        // /var/lib/php do not exist off-box).
        $runtime = sys_get_temp_dir() . '/api-introspect';
        foreach (['cache', 'tmp'] as $sub) {
            @mkdir("{$runtime}/{$sub}", 0o777, true);
        }
        $config->update('application.cacheDir', "{$runtime}/cache");
        $config->update('application.tempDir', "{$runtime}/tmp");

        // config.php derives contribDir relative to the config file, which
        // resolves to a non-existent src/opnsense/contrib. The bundled data
        // (iana tables consumed by CountryField etc.) actually lives at the
        // checkout's top-level contrib/.
        if (is_dir($contrib)) {
            $config->update('application.contribDir', $contrib);
        }

        // configd is not reachable off-box. Backend::configdStream sleeps in a
        // retry loop (up to connect_timeout seconds) on every call unless
        // simulate_mode is set, which short-circuits to a single iteration.
        // This keeps model construction (which may invoke ConfigdPopulateAct)
        // from blocking for minutes; option enums simply come back empty.
        $config->update('globals.simulate_mode', '1');

        $this->config = $config;

        return $config;
    }
}
