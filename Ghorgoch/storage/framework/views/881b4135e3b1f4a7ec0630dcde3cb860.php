<?php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
?>
# Laravel Boost Guidelines

## Foundational Context
This application is a Laravel application running on PHP <?php echo e(PHP_MAJOR_VERSION); ?>.<?php echo e(PHP_MINOR_VERSION); ?>. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run ___SINGLE_BACKTICK___<?php echo e($assist->composerCommand('show --direct')); ?>___SINGLE_BACKTICK___ to list direct dependencies with versions, or ___SINGLE_BACKTICK___<?php echo e($assist->composerCommand('show <vendor/package>')); ?>___SINGLE_BACKTICK___ for a single package.
- JS packages: check ___SINGLE_BACKTICK___package.json___SINGLE_BACKTICK___ for the installed versions.

<?php if(! empty(config('boost.purpose'))): ?>
Application purpose: <?php echo config('boost.purpose'); ?>


<?php endif; ?>

<?php if($assist->hasSkillsEnabled()): ?>
## Skills Activation
This project has domain-specific skills available in ___SINGLE_BACKTICK___**/skills/**___SINGLE_BACKTICK___. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.
<?php endif; ?>

## Conventions
- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, ___SINGLE_BACKTICK___isRegisteredForDiscounts___SINGLE_BACKTICK___, not ___SINGLE_BACKTICK___discount()___SINGLE_BACKTICK___.
- Check for existing components to reuse before writing a new one.

## Verification Scripts
- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture
- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling
- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run ___SINGLE_BACKTICK___<?php echo e($assist->nodePackageManagerCommand('run build')); ?>___SINGLE_BACKTICK___ or ask the user to run ___SINGLE_BACKTICK___<?php echo e($assist->nodePackageManagerCommand('run dev')); ?>___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___<?php echo e($assist->composerCommand('run dev')); ?>___SINGLE_BACKTICK___.

## Documentation Files
- You must only create documentation files if explicitly requested by the user.
<?php /**PATH D:\Dev\projects\EcommerceStore\Ghorgoch\storage\framework\views/a5489ab19e10b50f3164830e84ec85cf.blade.php ENDPATH**/ ?>