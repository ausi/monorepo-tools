<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\Monorepo;

use Contao\Monorepo\Git\Commit;
use Contao\Monorepo\Git\Repository;
use Contao\Monorepo\Git\Tree;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

class Splitter
{
    private $monorepoUrl;
    private $repoUrlsByFolder;
    private $cacheDir;
    private $objectsCachePath;
    private $repository;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var array<string,Commit>
     */
    private $commitCache = [];

    /**
     * @var array<string,Tree>
     */
    private $treeCache = [];

    public function __construct(string $monorepoUrl, array $repoUrlsByFolder, string $cacheDir, OutputInterface $output)
    {
        $this->monorepoUrl = $monorepoUrl;
        $this->repoUrlsByFolder = $repoUrlsByFolder;
        $this->cacheDir = $cacheDir;
        $this->objectsCachePath = $cacheDir.'/objects-v1.cache';
        $this->output = $output;

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException(sprintf('Unable to create directory %s', $cacheDir));
        }
    }

    public function split()
    {
        if (file_exists($this->objectsCachePath)) {
            $this->output->writeln("\nLoad data from cache...");
            [$this->commitCache, $this->treeCache] = unserialize(
                file_get_contents($this->objectsCachePath),
                [Commit::class, Tree::class]
            );
        }

        (new Filesystem())->remove($this->cacheDir.'/repo');
        (new Filesystem())->mkdir($this->cacheDir.'/repo');

        $this->output->writeln("\nLoad monorepo...");

        $this->repository = new Repository($this->cacheDir.'/repo', $this->output);
        $this->repository
            ->init()
            ->addRemote('mono', $this->monorepoUrl)
            ->fetch('mono')
            ->fetchTags('mono', 'remote/mono/')
        ;

        $branchCommits = $this->repository->getRemoteBranches('mono');

        $this->output->writeln("\nRead commits...");
        $commitObjects = $this->readCommits(array_values($branchCommits));

        if (empty($commitObjects)) {
            throw new \RuntimeException(sprintf('No commits found for: %s', print_r($branchCommits, true)));
        }

        $this->output->writeln("\nSplit commits...");
        $hashMapping = $this->splitCommits($commitObjects, $this->repoUrlsByFolder);

        if (empty($hashMapping)) {
            throw new \RuntimeException(sprintf('No hash mapping for commits: %s', print_r($commitObjects, true)));
        }

        $this->output->writeln("\nCreate branches...");
        foreach ($branchCommits as $branch => $commit) {
            foreach ($this->repoUrlsByFolder as $subRepo => $remote) {
                if (isset($hashMapping[$subRepo][$commit])) {
                    $this->repository->addBranch($subRepo.'/'.$branch, $hashMapping[$subRepo][$commit]);
                }
            }
        }

        $this->output->writeln("\nUpdate cache...");
        file_put_contents($this->objectsCachePath, serialize([$this->commitCache, $this->treeCache]));

        $this->output->writeln("\nDone 🎉");
    }

    private function splitCommits(array $commitObjects, array $subRepos)
    {
        $hashMapping = [];
        $pending = array_keys($commitObjects);
        while(count($pending)) {
            $current = array_pop($pending);
            foreach ($subRepos as $subRepo => $remote) {
                if (isset($hashMapping[$subRepo][$current])) {
                    continue 2;
                }
            }
            $missingParents = [];
            foreach ($commitObjects[$current]->getParentHashes() as $parent) {
                foreach ($subRepos as $subRepo => $remote) {
                    if (isset($hashMapping[$subRepo][$parent])) {
                        continue 2;
                    }
                }
                $missingParents[] = $parent;
            }
            if (count($missingParents)) {
                $pending[] = $current;
                foreach ($missingParents as $parent) {
                    $pending[] = $parent;
                }
                continue;
            }
            $this->splitCommit($current, $commitObjects[$current]->getTreeHash(), $hashMapping, $subRepos);
        }
        return $hashMapping;
    }

    private function splitCommit($commitHash, $treeHash, &$hashMapping, array $subRepos)
    {
        $newCommits = [];
        $treeObject = $this->getTreeObject($treeHash);
        $failure = true;
        foreach ($subRepos as $subRepo => $remote) {
            if (!$treeObject->getSubtreeHash($subRepo)) {
                continue;
            }
            $hashMapping[$subRepo][$commitHash] = $this->createNewCommit($commitHash, $treeObject->getSubtreeHash($subRepo), $hashMapping[$subRepo]);
            $failure = false;
        }
        if ($failure) {
            var_export($treeObject);
            throw new \RuntimeException('No subfolder found in '.$commitHash);
        }
    }

    private function createNewCommit(string $commit, string $tree, &$hashMapping)
    {
        $commitObject = $this->getCommitObject($commit);

        $newParents = [];
        foreach ($commitObject->getParentHashes() as $parent) {
            if (isset($hashMapping[$parent]) && !in_array($hashMapping[$parent], $newParents)) {
                $newParents[] = $hashMapping[$parent];
            }
        }

        $commitObject = $commitObject->withNewTreeAndParents($tree, $newParents);

        if (\count($commitObject->getParentHashes()) === 1 && $this->getCommitObject($commitObject->getParentHashes()[0])->getTreeHash() === $tree) {
            return $commitObject->getParentHashes()[0];
        }

        $this->repository->addObject($commitObject);

        $newHash = $commitObject->getHash();

        $this->commitCache[$newHash] = $commitObject;

        return $newHash;
    }

    private function readCommits(array $baseCommits): array
    {
        $commits = [];
        $pending = $baseCommits;

        while(count($pending)) {
            $current = array_shift($pending);
            if (isset($commits[$current])) {
                continue;
            }
            $commits[$current] = $this->getCommitObject($current);
            foreach($commits[$current]->getParentHashes() as $parent) {
                $pending[] = $parent;
            }
        }

        return $commits;
    }

    private function getTreeObject($hash)
    {
        if (isset($this->treeCache[$hash])) {
            return $this->treeCache[$hash];
        }

        $tree = $this->repository->getTree($hash);

        $this->treeCache[$hash] = $tree;

        return $tree;
    }

    private function getCommitObject($hash)
    {
        if (isset($this->commitCache[$hash])) {
            return $this->commitCache[$hash];
        }

        $commit = $this->repository->getCommit($hash);

        $this->commitCache[$hash] = $commit;

        return $commit;
    }
}
