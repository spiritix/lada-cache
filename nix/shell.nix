{
  perSystem =
    {
      inputs',
      pkgs,
      ...
    }:
    let
      php = pkgs.php84.buildEnv {
        extensions =
          { enabled, all }:
          enabled
          ++ (with all; [
            apcu
            pcntl
            pdo_mysql
            pdo_pgsql
            pdo_sqlite
            redis
          ]);
        extraConfig = ''
          apc.enable_cli=1
          memory_limit=1G
        '';
      };
    in
    {
      devShells.default = pkgs.mkShell {
        packages = [
          php
          php.packages.composer
          inputs'.maestro.packages.maestro
          pkgs.just
        ];
      };
    };
}
