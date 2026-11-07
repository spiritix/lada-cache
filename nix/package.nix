{
  perSystem =
    {
      inputs',
      lib,
      pkgs,
      ...
    }:
    {
      packages.default = pkgs.php84.buildComposerProject2 {
        pname = "lada-cache";
        version = "0.0.0";
        composer = inputs'.maestro.packages.maestro;

        src = lib.fileset.toSource {
          root = ../.;
          fileset = lib.fileset.difference (lib.fileset.gitTracked ../.) (
            lib.fileset.unions [
              ../flake.nix
              ../flake.lock
              ../nix
            ]
          );
        };

        vendorHash = "sha256-lGhG+8ermFmi11CCyIHeOqVBrULZ8+GcDBi6RP0AsJY=";
      };
    };
}
