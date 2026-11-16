#!/usr/bin/env python3
"""CycloneDX 1.5 SBOM of what a jt-vc-portal release contains (application level).

The PHP code has no third-party PHP packages (no Composer). Third-party parts are:
  - shipped in the image: the Jitsi IFrame API (assets/vendor/jitsi-external-api.js) and the
    Noto Sans TC font (lib/fonts, used for PDF export);
  - loaded by the browser at runtime from cdn.jsdelivr.net with Subresource Integrity:
    Chart.js, flatpickr, qrcodejs (versions and SRI hashes are read from the PHP sources);
  - jibri-recordings-api (optional, runs on the Jibri host): Python standard library only.
Everything is read from the source tree, nothing is hard-coded except licences.
The operating system packages of the Docker image (PHP, Apache, Debian) are in the separate
image SBOM produced with syft (see tools/ci-build-release.sh).

  tools/make-sbom.py <source dir> > jt-vc-portal-X.Y.Z-sbom.cdx.json
"""
import base64
import datetime
import glob
import hashlib
import json
import os
import re
import struct
import sys
import uuid

# licences of the third-party parts (not stated in the files themselves)
LICENSES = {'chart.js': 'MIT', 'flatpickr': 'MIT', 'qrcodejs': 'MIT'}


def sha(path, alg):
    h = hashlib.new(alg)
    with open(path, 'rb') as fh:
        for b in iter(lambda: fh.read(1 << 20), b''):
            h.update(b)
    return h.hexdigest()


def font_revision(path):
    """fontRevision (Fixed 16.16) from the TrueType 'head' table."""
    with open(path, 'rb') as fh:
        data = fh.read(4096)
    num = struct.unpack('>H', data[4:6])[0]
    for i in range(num):
        tag, _, off, _ = struct.unpack('>4sIII', data[12 + 16 * i:28 + 16 * i])
        if tag == b'head':
            with open(path, 'rb') as fh:
                fh.seek(off + 4)
                rev = struct.unpack('>i', fh.read(4))[0] / 65536.0
            return '%.3f' % rev
    return 'unknown'


def cdn_components(src):
    """<script|link ... src|href="https://cdn.jsdelivr.net/npm/NAME@VER/..." integrity="sha384-..."> in the PHP sources."""
    pat = re.compile(r'''https://cdn\.jsdelivr\.net/npm/((?:@[\w.-]+/)?[\w.-]+)@([\w.-]+)(/[^"'\s]*)?["'][^>]*?integrity=["'](sha\d+)-([A-Za-z0-9+/=]+)["']''')
    seen = {}
    for php in sorted(glob.glob(os.path.join(src, '**', '*.php'), recursive=True)):
        if '/tests/' in php:
            continue
        with open(php, encoding='utf-8', errors='replace') as fh:
            text = fh.read()
        for name, ver, path, alg, b64 in pat.findall(text):
            key = (name, ver)
            c = seen.setdefault(key, {'files': set(), 'hashes': {}})
            c['files'].add((path or '/').lstrip('/'))
            c['hashes'][(alg, b64)] = os.path.relpath(php, src)
    comps = []
    for (name, ver), c in sorted(seen.items()):
        purl = 'pkg:npm/%s@%s' % (name.replace('@', '%40'), ver)
        comps.append({
            'type': 'library', 'name': name, 'version': ver, 'purl': purl, 'bom-ref': purl,
            'licenses': [{'license': {'id': LICENSES.get(name, 'NOASSERTION')}}] if name in LICENSES else [{'license': {'name': 'NOASSERTION'}}],
            'hashes': [{'alg': 'SHA-' + alg[3:], 'content': base64.b64decode(b).hex()} for (alg, b) in sorted(c['hashes'])],
            'properties': [
                {'name': 'jt-vc-portal:delivery', 'value': 'loaded by the browser from cdn.jsdelivr.net with Subresource Integrity'},
                {'name': 'jt-vc-portal:files', 'value': ', '.join(sorted(c['files']))},
            ],
        })
    return comps


def main():
    src = sys.argv[1] if len(sys.argv) > 1 else '.'
    with open(os.path.join(src, 'config.php'), encoding='utf-8') as fh:
        m = re.search(r"define\('APP_VERSION',\s*'([^']+)'\)", fh.read())
    version = m.group(1) if m else 'unknown'
    comps = []

    js = os.path.join(src, 'assets', 'vendor', 'jitsi-external-api.js')
    if os.path.exists(js):
        h = sha(js, 'sha256')
        comps.append({
            'type': 'library', 'name': 'jitsi-meet-external-api', 'version': 'sha256-' + h[:16],
            'purl': 'pkg:generic/jitsi/external_api.js@sha256-%s' % h[:16], 'bom-ref': 'jitsi-external-api',
            'licenses': [{'license': {'id': 'Apache-2.0'}}],
            'hashes': [{'alg': 'SHA-256', 'content': h}, {'alg': 'SHA-384', 'content': sha(js, 'sha384')}],
            'properties': [
                {'name': 'jt-vc-portal:delivery', 'value': 'shipped (assets/vendor/jitsi-external-api.js), pinned copy with SRI'},
                {'name': 'jt-vc-portal:source', 'value': 'https://8x8.vc/external_api.js (Jitsi IFrame API; the file carries no version number)'},
            ],
        })

    font = os.path.join(src, 'lib', 'fonts', 'NotoSansTC-Regular.ttf')
    if os.path.exists(font):
        rev = font_revision(font)
        comps.append({
            'type': 'file', 'name': 'Noto Sans TC Regular', 'version': rev,
            'purl': 'pkg:generic/google/noto-sans-tc@%s' % rev, 'bom-ref': 'noto-sans-tc',
            'licenses': [{'license': {'id': 'OFL-1.1'}}],
            'hashes': [{'alg': 'SHA-256', 'content': sha(font, 'sha256')}],
            'properties': [{'name': 'jt-vc-portal:delivery', 'value': 'shipped (lib/fonts, used for PDF export)'}],
        })

    comps += cdn_components(src)

    jra = os.path.join(src, 'jibri-recordings-api', 'server.py')
    if os.path.exists(jra):
        comps.append({
            'type': 'application', 'name': 'jibri-recordings-api', 'version': version, 'bom-ref': 'jibri-recordings-api',
            'licenses': [{'license': {'id': 'GPL-3.0-only'}}],
            'hashes': [{'alg': 'SHA-256', 'content': sha(jra, 'sha256')}],
            'properties': [
                {'name': 'jt-vc-portal:delivery', 'value': 'optional companion service, installed separately on the Jibri host'},
                {'name': 'jt-vc-portal:dependencies', 'value': 'Python standard library only'},
            ],
        })

    bom = {
        'bomFormat': 'CycloneDX', 'specVersion': '1.5', 'serialNumber': 'urn:uuid:%s' % uuid.uuid4(), 'version': 1,
        'metadata': {
            'timestamp': datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat(),
            'component': {'type': 'application', 'name': 'jt-vc-portal', 'version': version,
                          'licenses': [{'license': {'id': 'GPL-3.0-only'}}],
                          'purl': 'pkg:github/jasoncheng7115/jt-vc-portal@v%s' % version,
                          'bom-ref': 'jt-vc-portal@%s' % version},
            'properties': [
                {'name': 'jt-vc-portal:php-packages', 'value': 'none (no Composer dependencies; all PHP code is part of this project)'},
                {'name': 'jt-vc-portal:runtime', 'value': 'PHP 8.3+ with Apache; the Docker image contents are listed in the separate image SBOM'},
            ],
        },
        'components': comps,
        'dependencies': [{'ref': 'jt-vc-portal@%s' % version, 'dependsOn': [c['bom-ref'] for c in comps]}],
    }
    json.dump(bom, sys.stdout, indent=1, ensure_ascii=False)
    sys.stdout.write('\n')


if __name__ == '__main__':
    main()
