<?php
/**
 * Route a request to the Composer repository if it owns the path.
 *
 * @param Wpup_ComposerRepository $repo
 * @param string $requestUri Raw REQUEST_URI (path plus optional query string).
 * @param array $headers Header name => value.
 * @return Wpup_ComposerResponse|null
 */
function wpup_route_composer(Wpup_ComposerRepository $repo, $requestUri, array $headers) {
	$path = parse_url((string)$requestUri, PHP_URL_PATH);
	if ( !is_string($path) ) {
		$path = '/';
	}
	return $repo->handle($path, new Wpup_Headers($headers));
}
