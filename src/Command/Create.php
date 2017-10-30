<?php

declare(strict_types=1);

/*
 * This file is part of the Packagist Mirror.
 *
 * For the full license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Webs\Mirror\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webs\Mirror\ShortName;
use Webs\Mirror\Provider;
use stdClass;
use Generator;

/**
 * Create a mirror.
 *
 * @author Webysther Nunes <webysther@gmail.com>
 */
class Create extends Base
{
    use ShortName;

    /**
     * @var stdClass
     */
    protected $providers;

    /**
     * @var array
     */
    protected $providerIncludes;

    /**
     * @var string
     */
    protected $currentProvider;

    /**
     * @var Clean
     */
    protected $clean;

    /**
     * {@inheritdoc}
     */
    public function __construct($name = '')
    {
        parent::__construct('create');
        $this->setDescription(
            'Create/update packagist mirror'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output):int
    {
        $this->progressBar->setConsole($input, $output);
        $this->package->setConsole($input, $output);
        $this->provider->setConsole($input, $output);

        // Download providers, with repository, is incremental
        if ($this->downloadProviders()->stop()) {
            return $this->getExitCode();
        }

        // Download packages
        if ($this->downloadPackages()->stop()) {
            return $this->getExitCode();
        }

        // Switch .packagist.json to packagist.json
        if ($this->switch()->stop()) {
            return $this->getExitCode();
        }

        $this->setExitCode($this->clean->execute($input, $output));

        if ($this->initialized) {
            $this->filesystem->remove(self::INIT);
        }

        return $this->getExitCode();
    }

    /**
     * @param Clean $clean
     */
    public function setClean(Clean $clean):Create
    {
        $this->clean = $clean;

        return $this;
    }

    /**
     * @return int
     */
    protected function getExitCode():int
    {
        $this->generateHtml();

        return $this->exitCode;
    }

    /**
     * Load main packages.json.
     *
     * @return Create
     */
    protected function loadPackagesJson():Create
    {
        $this->output->writeln(
            'Loading providers from <info>'.$this->http->getBaseUri().'</>'
        );

        $this->providers = $this->provider->addFullPath(
            $this->package->loadMainJson()
        );

        return $this;
    }

    /**
     * Check if packages.json was changed.
     *
     * @return bool
     */
    protected function isEqual():bool
    {
        // if 'p/...' folder not found
        if (!$this->filesystem->has(self::TO)) {
            $this->filesystem->touch(self::INIT);
        }

        $this->initialized = $this->filesystem->has(self::INIT);

        $newPackages = json_encode($this->providers, JSON_PRETTY_PRINT);

        // No provider changed? Just relax...
        if ($this->canSkip(self::MAIN)) {
            $old = $this->filesystem->getHashFile(self::MAIN);
            $new = $this->filesystem->getHash($newPackages);

            if ($old == $new) {
                $this->output->writeln(self::MAIN.' <info>updated</>');
                $this->setExitCode(0);

                return true;
            }
        }

        $this->filesystem->write(self::DOT, $newPackages);

        return false;
    }

    /**
     * Switch current packagist.json to space and .packagist to packagist.json.
     *
     * @return bool True if work, false otherside
     */
    protected function switch():Create
    {
        // If .packages.json dont exists
        if (!$this->filesystem->has(self::DOT)) {
            return $this;
        }

        // Move to new location
        $this->filesystem->move(self::DOT, self::MAIN);

        return $this;
    }

    /**
     * Download packages.json & provider-xxx$xxx.json.
     *
     * @return bool True if work, false otherside
     */
    protected function downloadProviders():Create
    {
        if ($this->loadPackagesJson()->isEqual()) {
            return $this;
        }

        $this->providerIncludes = $this->provider->normalize($this->providers);
        $generator = $this->getProvidersGenerator();

        if (empty(iterator_to_array($generator))) {
            $this->output->writeln('All providers are <info>updated</>');

            return $this->setExitCode(0);
        }

        $this->progressBar->start(count($this->providerIncludes));

        $this->http->pool(
            $generator,
            // Success
            function ($body, $path) {
                $this->filesystem->write($path, $body);
            },
            // If complete, even failed and success
            function () {
                $this->progressBar->progress();
            }
        );

        $this->progressBar->end();
        $this->showErrors();

        return $this;
    }

    /**
     * Download packages.json & provider-xxx$xxx.json.
     *
     * @return Generator Providers downloaded
     */
    protected function getProvidersGenerator():Generator
    {
        $providerIncludes = array_keys($this->providerIncludes);
        foreach ($providerIncludes as $uri) {
            if ($this->canSkip($uri)) {
                $this->progressBar->progress();
                continue;
            }

            yield $uri => $this->http->getRequest($uri);
        }
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    protected function canSkip(string $path):bool
    {
        if ($this->filesystem->has($path) && !$this->initialized) {
            return true;
        }

        return false;
    }

    /**
     * Show errors.
     *
     * @return Create
     */
    protected function showErrors():Create
    {
        if (!$this->isVerbose()) {
            return $this;
        }

        $errors = $this->http->getPoolErrors();
        if (count($errors) === 0) {
            return $this;
        }

        foreach ($errors as $path => $reason) {
            $shortname = $this->shortname($path);
            $error = $reason->getCode();
            $host = $reason->getRequest()->getUri()->getHost();

            $this->output->write(
                "<comment>$shortname</> failed from ".
                "<info>$host</> with HTTP error"
            );

            if (!$error) {
                $this->output->writeln(
                    ':'.PHP_EOL.'<error>'.$reason->getMessage().'</>'
                );
                continue;
            }

            $this->output->writeln(" <error>$error</>");
        }

        $this->output->write(PHP_EOL);

        return $this;
    }

    /**
     * Disable mirror when due lots of errors.
     */
    protected function disableDueErrors()
    {
        $errors = $this->http->getPoolErrors();
        if (count($errors) === 0) {
            return $this;
        }

        $counter = [];

        foreach ($errors as $reason) {
            $uri = $reason->getRequest()->getUri();
            $host = $uri->getScheme().'://'.$uri->getHost();

            if (!isset($counter[$host])) {
                $counter[$host] = 0;
            }

            ++$counter[$host];
        }

        $mirrors = $this->http->getMirror()->toArray();

        foreach ($mirrors as $mirror) {
            $total = $counter[$mirror];
            if ($total < 1000) {
                continue;
            }

            $this->output->write(PHP_EOL);
            $this->output->writeln(
                '<error>Due to '.
                $total.' errors mirror '.
                $mirror.' will be disabled</>'
            );
            $this->output->write(PHP_EOL);
            $this->http->getMirror()->remove($mirror);
        }

        return $this;
    }

    /**
     * @param string $uri
     *
     * @return Create
     */
    protected function loadProviderPackages(string $uri):Create
    {
        $providers = json_decode($this->filesystem->read($uri))->providers;
        $this->providerPackages = $this->package->normalize($providers);
        $this->currentProvider = $uri;

        return $this;
    }

    /**
     * Download packages listed on provider-*.json on public/p dir.
     *
     * @return Create
     */
    protected function downloadPackages():Create
    {
        $totalProviders = count($this->providerIncludes);
        $currentProvider = 0;

        $providerIncludes = array_keys($this->providerIncludes);
        foreach ($providerIncludes as $uri) {
            $shortname = $this->shortname($uri);

            $this->output->writeln(
                '['.++$currentProvider.'/'.$totalProviders.']'.
                ' Loading packages from <info>'.$shortname.'</> provider'
            );

            $this->http->useMirrors();
            $generator = $this->loadProviderPackages($uri)->getPackagesGenerator();
            if (empty(iterator_to_array($generator))) {
                continue;
            }

            $this->progressBar->start(count($this->providerPackages));
            $this->poolPackages($generator);
            $this->progressBar->end();
            $this->showErrors()->disableDueErrors()->fallback();
        }

        return $this;
    }

    /**
     * Download only a package.
     *
     * @return Generator Providers downloaded
     */
    protected function getPackagesGenerator():Generator
    {
        $providerPackages = array_keys($this->providerPackages);
        foreach ($providerPackages as $uri) {
            if ($this->filesystem->has($uri)) {
                $this->progressBar->progress();
                continue;
            }

            if ($this->initialized) {
                $uri = $this->http->getMirror()->getNext().'/'.$uri;
            }

            yield $uri => $this->http->getRequest($uri);
        }
    }

    /**
     * @param Generator $generator
     * @param bool|bool $useMirrors
     *
     * @return Create
     */
    protected function poolPackages(Generator $generator):Create
    {
        $this->http->pool(
            $generator,
            // Success
            function ($body, $path) {
                $this->filesystem->write($path, $body);
                $this->package->setDownloaded($path);
            },
            // If complete, even failed and success
            function () {
                $this->progressBar->progress();
            }
        );

        return $this;
    }

    protected function fallback():Create
    {
        $total = count($this->http->getPoolErrors());

        if (!$total) {
            return $this;
        }

        $shortname = $this->shortname($this->currentProvider);

        $this->output->writeln(
            'Fallback packages from <info>'.$shortname.
            '</> provider to main mirror <info>'.$this->http->getBaseUri().'</>'
        );

        $this->providerPackages = $this->http->getPoolErrors();
        $generator = $this->getPackagesGenerator();
        $this->progressBar->start($total);
        $this->poolPackages($generator);
        $this->progressBar->end();
        $this->showErrors();
    }

    /**
     * Generate HTML of index.html.
     */
    protected function generateHtml():Create
    {
        ob_start();
        include getcwd().'/resources/index.html.php';
        $this->filesystem->write('index.html', ob_get_clean());

        return $this;
    }
}
