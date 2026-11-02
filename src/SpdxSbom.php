<?php

/**
 * @package Localzet Packager
 * @link https://github.com/localzet/Packager
 * @author Ivan Zorin <creator@localzet.com>
 * @copyright Copyright © 2016-2026 Localzet Group. All rights reserved.
 * @license https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Localzet\Packager;

use JsonException;

final class SpdxSbom
{
    /** @throws JsonException */
    public function encode(Manifest $manifest, string $artifactPath, string $artifactHash): string
    {
        $rootId = 'SPDXRef-Application';
        $rootPackage = [
            'name' => $manifest->projectName(),
            'SPDXID' => $rootId,
            'versionInfo' => $manifest->projectVersion() ?? 'NOASSERTION',
            'downloadLocation' => 'NOASSERTION',
            'filesAnalyzed' => false,
            'licenseConcluded' => 'NOASSERTION',
            'licenseDeclared' => 'NOASSERTION',
            'copyrightText' => 'NOASSERTION',
            'checksums' => [['algorithm' => 'SHA256', 'checksumValue' => $artifactHash]],
            'packageFileName' => basename($artifactPath),
        ];

        $packageIds = [];
        $packages = [$rootPackage];
        foreach ($manifest->composerPackages() as $package) {
            $name = strtolower((string)$package['name']);
            $id = 'SPDXRef-Package-' . hash('sha256', $name);
            $packageIds[$name] = $id;
            $version = (string)$package['version'];
            $packages[] = [
                'name' => (string)$package['name'],
                'SPDXID' => $id,
                'versionInfo' => $version,
                'downloadLocation' => 'NOASSERTION',
                'filesAnalyzed' => false,
                'licenseConcluded' => 'NOASSERTION',
                'licenseDeclared' => 'NOASSERTION',
                'copyrightText' => 'NOASSERTION',
                'externalRefs' => [[
                    'referenceCategory' => 'PACKAGE-MANAGER',
                    'referenceType' => 'purl',
                    'referenceLocator' => $this->composerPurl((string)$package['name'], $version),
                ]],
            ];
        }

        $relationships = [['spdxElementId' => 'SPDXRef-DOCUMENT', 'relationshipType' => 'DESCRIBES', 'relatedSpdxElement' => $rootId]];
        foreach ($manifest->projectRequirements() as $name => $_constraint) {
            $dependencyId = $packageIds[strtolower($name)] ?? null;
            if ($dependencyId !== null) {
                $relationships[] = ['spdxElementId' => $rootId, 'relationshipType' => 'DEPENDS_ON', 'relatedSpdxElement' => $dependencyId];
            }
        }
        foreach ($manifest->composerPackages() as $package) {
            $sourceId = $packageIds[strtolower((string)$package['name'])];
            $requirements = $package['require'] ?? [];
            if (!is_array($requirements)) {
                continue;
            }
            foreach ($requirements as $name => $_constraint) {
                if (!is_string($name)) {
                    continue;
                }
                $dependencyId = $packageIds[strtolower($name)] ?? null;
                if ($dependencyId !== null) {
                    $relationships[] = ['spdxElementId' => $sourceId, 'relationshipType' => 'DEPENDS_ON', 'relatedSpdxElement' => $dependencyId];
                }
            }
        }

        $document = [
            'spdxVersion' => 'SPDX-2.3',
            'dataLicense' => 'CC0-1.0',
            'SPDXID' => 'SPDXRef-DOCUMENT',
            'name' => $manifest->projectName() . ' standalone artifact',
            'documentNamespace' => 'https://spdx.org/spdxdocs/localzet-packager-' . $artifactHash . '-' . bin2hex(random_bytes(8)),
            'documentDescribes' => [$rootId],
            'creationInfo' => [
                'creators' => ['Tool: Localzet Packager'],
                'created' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            'packages' => $packages,
            'relationships' => $relationships,
        ];

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    private function composerPurl(string $name, string $version): string
    {
        $segments = explode('/', strtolower($name));
        $name = implode('/', array_map('rawurlencode', $segments));
        return 'pkg:composer/' . $name . '@' . rawurlencode(ltrim($version, 'vV'));
    }
}
