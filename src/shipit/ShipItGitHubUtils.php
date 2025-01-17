<?hh
/**
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 * @format
 */

/**
 * This file was moved from fbsource to www. View old history in diffusion:
 * https://fburl.com/gl8oxfjx
 */
namespace Facebook\ShipIt;

use namespace HH\Lib\{Regex, Str}; // @oss-enable

type ShipItGitHubCredentials = shape(
  'name' => string,
  'user' => ?string,
  'email' => string,
  'password' => ?string,
  'access_token' => ?string,
);

abstract class ShipItGitHubUtils {
  /** Fetch information on a user that has permission to write to a project.
   *
   * For example, for projects on github.com/facebook/, it will return
   * information on facebook-github-bot user
   *
   * This is used by ::initializeRepo().
   */
  const string GIT_HTTPS_URL_PREFIX = 'https://';
  public abstract static function genCredentialsForProject(
    string $organization,
    string $project,
  ): Awaitable<ShipItGitHubCredentials>;

  /**
   * Configure the user and origin for a repository, cloning if necessary.
   *
   * - requires getCredentialsForProject() to be implemented
   * - configures 'origin' to be authenticated HTTPS
   */
  final public static async function genInitializeRepo(
    string $organization,
    string $project,
    string $local_path,
    ShipItTransport $transport,
    ?ShipItGitHubCredentials $credentials,
  ): Awaitable<void> {
    $git_config = (string $key, string $value) ==>
      new ShipItShellCommand($local_path, 'git', 'config', $key, $value);

    switch ($transport) {
      case ShipItTransport::SSH:
        invariant(
          $credentials === null,
          'Credentials should not be specified for SSH transport',
        );
        $origin =
          Str\format('git@github.com:%s/%s.git', $organization, $project);

        await self::genCloneAndVerifyRepo($origin, $local_path);
        break;
      case ShipItTransport::HTTPS:
        $origin =
          Str\format('https://github.com/%s/%s.git', $organization, $project);
        if ($credentials === null) {
          await self::genCloneAndVerifyRepo($origin, $local_path);
          break;
        }
        $origin = self::authHttpsRemoteUrl($origin, $transport, $credentials);
        await self::genCloneAndVerifyRepo($origin, $local_path);

        await $git_config('user.name', $credentials['name'])->genRun();
        await $git_config('user.email', $credentials['email'])->genRun();
        break;
    }

    await $git_config('remote.origin.url', $origin)->genRun();
  }

  public static function authHttpsRemoteUrl(
    string $remote_url,
    ShipItTransport $transport,
    ShipItGitHubCredentials $credentials,
  ): string {
    if ($transport !== ShipItTransport::HTTPS) {
      return $remote_url;
    }
    $access_token = $credentials['access_token'];
    $auth_user = $access_token;
    if ($auth_user === null) {
      $user = $credentials['user'];
      $password = $credentials['password'];
      invariant(
        $user is nonnull && $password is nonnull,
        'Either an access token or user/password is required.',
      );
      $auth_user =
        Str\format('%s:%s', PHP\urlencode($user), PHP\urlencode($password));
    }
    if (Str\search($remote_url, self::GIT_HTTPS_URL_PREFIX) === 0) {
      $prefix_len = Str\length(self::GIT_HTTPS_URL_PREFIX);
      return Str\slice($remote_url, 0, $prefix_len).
        $auth_user.
        '@'.
        Str\slice($remote_url, $prefix_len);
    }
    return $remote_url;
  }

  private static async function genCloneAndVerifyRepo(
    string $origin,
    string $local_path,
  ): Awaitable<void> {
    if (!PHP\file_exists($local_path)) {
      await ShipItRepoGIT::genCloneRepo($origin, $local_path);
    }
    invariant(
      PHP\file_exists($local_path.'/.git'),
      '%s is not a git repo',
      $local_path,
    );
  }

  final public static async function makeAPIRequest(
    ShipItGitHubCredentials $credentials,
    string $path,
  ): Awaitable<vec<string>> {
    $results = vec[];
    $request_headers = vec[
      'Accept: application/vnd.github.v3.patch',
    ];

    $access_token = $credentials['access_token'];
    $use_oauth = $access_token !== null;

    if ($use_oauth) {
      $request_headers[] =
        Str\format('Authorization: token %s', $access_token ?? 'null');
    }

    $url = Str\format('https://api.github.com%s', $path);

    while ($url !== null) {
      $ch = PHP\curl_init($url);
      PHP\curl_setopt($ch, \CURLOPT_USERAGENT, 'Facebook/ShipIt');
      PHP\curl_setopt($ch, \CURLOPT_HTTPHEADER, $request_headers);
      if (!$use_oauth) {
        PHP\curl_setopt(
          $ch,
          \CURLOPT_USERPWD,
          Str\format(
            '%s:%s',
            $credentials['user'] ?? 'null',
            $credentials['password'] ?? 'null',
          ),
        );
      }
      PHP\curl_setopt($ch, \CURLOPT_HEADER, 1);
      /* @lint-ignore AWAIT_IN_LOOP Intentional serial await */
      $response = await \HH\Asio\curl_exec($ch);
      $header_len = PHP\curl_getinfo($ch, \CURLINFO_HEADER_SIZE);
      $response_header = Str\slice($response, 0, $header_len);
      $results[] = Str\slice($response, $header_len);

      $url = null;
      foreach (Str\split(Str\trim($response_header), "\n") as $header_line) {
        if (Str\slice($header_line, 0, 5) === 'HTTP/') {
          continue;
        }
        $sep = Str\search($header_line, ':');
        if ($sep === null) {
          continue;
        }

        $name = Str\lowercase(Str\slice($header_line, 0, $sep));
        if ($name === 'link') {
          $matches = Regex\first_match(
            $header_line,
            re"@<(?<next>https://api.github.com[^>]+)>; rel=\"next\"@",
          );
          if ($matches !== null) {
            $url = $matches['next'];
            break;
          }
        }
      }
    }
    return $results;
  }
}
