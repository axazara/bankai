@import('vendor/axazara/bankai/src/Envoy.blade.php');

@task("run:after_deploy")
    cd {{ $release }}
    true

# Here you can add your own commands to run after deploy
@endtask


