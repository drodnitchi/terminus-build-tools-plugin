<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a Git PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ProcessUtils;
use Consolidation\AnnotatedCommand\AnnotationData;
use Consolidation\AnnotatedCommand\CommandData;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Semver\Comparator;
use Pantheon\TerminusBuildTools\Utility\MultiDevRetention;

/**
 * Env Delete Command
 */
class EnvDeleteCommand extends BuildToolsBase
{
    /**
     * Delete all of the build environments matching the pattern for transient
     * CI builds, i.e., all multidevs whose name begins with "ci-".
     *
     * @command build:env:delete:ci
     * @aliases build-env:delete:ci
     *
     * @param string $site_id Site name
     * @option keep Number of environments to keep
     * @option dry-run Only print what would be deleted; do not delete anything.
     */
    public function deleteBuildEnvCI(
        $site_id,
        $options = [
            'keep' => 0,
            'dry-run' => false,
        ])
    {
        $retentionController = $this->createRetentionController($site_id, self::TRANSIENT_CI_DELETE_PATTERN);
        if (!$retentionController) {
            return;
        }

        $retentionController->eligibleIfOldest($options['keep']);

        return $this->confirmAndDeleteEnvironments($retentionController, $options['dry-run']);
    }

    /**
     * Delete all of the build environments matching the pattern for pull
     * request branches where the pull requests are closed, i.e., all
     * multidevs whose name begins with "pr-" that were generated by
     * now-closed pull requests.
     *
     * @command build:env:delete:pr
     * @aliases build-env:delete:pr
     *
     * @param string $site_id Site name
     * @option dry-run Only print what would be deleted; do not delete anything.
     */
    public function deleteBuildEnvPR(
        $site_id,
        $options = [
            'dry-run' => false,
        ])
    {
        $retentionController = $this->createRetentionController($site_id, self::PR_BRANCH_DELETE_PATTERN);
        if (!$retentionController) {
            return;
        }

        $retentionController->eligibleIfClosedPRExists();

        return $this->confirmAndDeleteEnvironments($retentionController, $options['dry-run']);
    }

    /**
     * createRetentionController returns an object that can be used to determine
     * which multidevs should be retained, and which are eligible for removal.
     *
     * @return MultiDevRetention|null
     */
    protected function createRetentionController($site_id, $multidev_delete_pattern)
    {
        // Look up the oldest environments matching the delete pattern
        $oldestEnvironments = $this->oldestEnvironments($site_id, '^' . $multidev_delete_pattern);

        // Bail out if nothing matched
        if (empty($oldestEnvironments)) {
            $this->log()->notice('No environments matched the provided pattern "{pattern}".', ['pattern' => $multidev_delete_pattern]);
            return null;
        }

        // Reduce result list down to just the env id ('ci-123' et. al.)
        $oldestEnvironments = array_map(
            function ($item) {
                return $item['id'];
            },
            $oldestEnvironments
        );

        // Find the URL to the remote origin
        $remoteUrlFromGit = exec('git config --get remote.origin.url');

        // Find the URL of the remote origin stored in the build metadata
        $remoteUrl = $this->retrieveRemoteUrlFromBuildMetadata($site_id, $oldestEnvironments);

        // Create a git repository service provider appropriate to the URL and ensure credentials are present
        $this->inferGitProviderFromUrl($remoteUrl);
        $this->providerManager()->validateCredentials();

        // Bail if there is a URL mismatch
        if (!empty($remoteUrlFromGit) && ($this->projectFromRemoteUrl($remoteUrlFromGit) != $this->projectFromRemoteUrl($remoteUrl))) {
            throw new TerminusException('Remote repository mismatch: local repository, {gitrepo} is different than the repository {metadatarepo} associated with the site {site}.', ['gitrepo' => $this->projectFromRemoteUrl($remoteUrlFromGit), 'metadatarepo' => $this->projectFromRemoteUrl($remoteUrl), 'site' => $site_id]);
        }

        // Create a git repository service provider appropriate to the URL and ensure credentials are present
        $provider = $this->inferGitProviderFromUrl($remoteUrl);
        $this->providerManager()->validateCredentials();

        $project = $this->projectFromRemoteUrl($remoteUrl);

        $retentionController = new MultiDevRetention(
            $provider,
            $oldestEnvironments,
            $multidev_delete_pattern,
            $project,
            $site_id
        );

        return $retentionController;
    }

    /**
     * @command build:env:delete
     * @aliases build-env:delete
     *
     * This function had the potential to be too destructive if called from ci
     * using --yes with an overly-inclusive delete pattern, e.g. if an
     * environment variable for a recurring build is incorrectly altered.
     * It was therefore removed in favor of safer options.
     */
    public function deleteBuildEnv(
        $site_id,
        $multidev_delete_pattern = self::DEFAULT_DELETE_PATTERN,
        $options = [
            'keep' => 0,
            'preserve-prs' => true,
            'preserve-if-branch' => false,
            'delete-branch' => false,
            'dry-run' => false,
        ])
    {
        throw new TerminusException('The command build:env:delete has been removed. Please use build:env:delete:ci or build:env:delete:pr instead.');
    }

    protected function confirmAndDeleteEnvironments(MultiDevRetention $retentionController, $dryRun = false)
    {
        $environmentsToKeep = $retentionController->retain();
        $environmentsToDelete = $retentionController->eligible();
        $deleteBranch = true;

        // Make a display message of the environments to delete and keep
        $deleteList = implode(',', $environmentsToDelete);
        $keepList = implode(',', $environmentsToKeep);
        if (empty($keepList)) {
            $keepList = 'none of the build environments';
        }

        // Stop if there is nothing to delete.
        if (empty($environmentsToDelete)) {
            $this->log()->notice('Nothing to delete. Keeping {keepList}.', ['keepList' => $keepList,]);
            return;
        }

        if ($dryRun) {
            $this->log()->notice('Dry run: would delete {deleteList} and keep {keepList}', ['deleteList' => $deleteList, 'keepList' => $keepList]);
            return;
        }

        if (!$this->confirm('Are you sure you want to delete {deleteList} and keep {keepList}?', ['deleteList' => $deleteList, 'keepList' => $keepList])) {
            return;
        }

        // Delete each of the selected environments.
        $site_id = $retentionController->siteId();
        foreach ($environmentsToDelete as $env_id) {
            $site_env_id = "{$site_id}.{$env_id}";

            list (, $env) = $this->getSiteEnv($site_env_id);
            $this->deleteEnv($env, $deleteBranch);
        }
    }
}
