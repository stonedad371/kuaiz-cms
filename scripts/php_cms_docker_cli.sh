#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
repository_root=$(CDPATH= cd -- "$script_dir/.." && pwd)
php_image=${KUAIZ_PHP_DOCKER_IMAGE:-kuaiz/php-cms-host-test:apache}

exec docker run --rm --network none \
  --user "$(id -u):$(id -g)" \
  --volume "$repository_root:$repository_root:ro" \
  --volume /private:/private \
  --volume /tmp:/tmp \
  --workdir "$repository_root" \
  "$php_image" php "$@"
