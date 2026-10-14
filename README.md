# kurtcampbell/wp-mvc

Discovery-based MVC **system layer** for WordPress plugins.

## Fixed namespace (do not rename)

**`KurtCampbell\WpMvc` is permanent.**

- Product plugins `use` / `extend` these classes.
- Do **not** copy `src/` into a plugin or rename the namespace.
- Update via Composer: `composer update kurtcampbell/wp-mvc`.

App code (controllers, models, utilities) lives in **your** plugin namespace (e.g. `KurtCampbell\MyPlugin`).

## Require

```json
{
  "require": {
    "kurtcampbell/wp-mvc": "^1.0"
  }
}
```

### Path (local, before GitHub)

From a sibling starter (this kit layout):

```json
"repositories": [
  {
    "type": "path",
    "url": "../wp-mvc",
    "options": { "symlink": true }
  }
]
```

### VCS (after you push this package to GitHub)

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/YOUR_USER/wp-mvc.git"
  }
]
```

Then tag releases (`v1.0.0`) and run `composer update kurtcampbell/wp-mvc`.

## Boot sequence

1. `require vendor/autoload.php`
2. `PluginContext::boot( new YourPluginIdentity( __FILE__ ) )`
3. `new PluginKernel()` → `activate()` / `run()`

Implement `KurtCampbell\WpMvc\PluginIdentity` in the product plugin.

## GitHub publish (manual)

1. Create an empty GitHub repo.
2. Push the contents of this directory (`composer.json`, `src/`, README, CHANGELOG).
3. `git tag v1.0.0 && git push --tags`
4. Point consuming plugins at the VCS URL above.
