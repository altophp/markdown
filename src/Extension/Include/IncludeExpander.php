<?php

declare(strict_types=1);

/*
 * This file is part of the ALTO library.
 *
 * © 2026-present Simon André
 *
 * For full copyright and license information, please see
 * the LICENSE file distributed with this source code.
 */

namespace Alto\Markdown\Extension\Include;

use Alto\Markdown\Exception\ResourceDeniedException;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\SyntaxParser;
use Alto\Markdown\Profile\CommonMarkProfile;
use Alto\Markdown\Profile\ComposedProfile;
use Alto\Markdown\Profile\ProfileCompiler;
use Alto\Markdown\Resource\ResolvedResource;
use Alto\Markdown\Resource\ResourceRequest;
use Alto\Markdown\Resource\ResourceResolver;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class IncludeExpander
{
    private SyntaxParser $parser;

    private int $includeKind;

    public function __construct(
        private ResourceResolver $resolver,
        private IncludePolicy $policy,
    ) {
        $profile = new ProfileCompiler()->compile(new ComposedProfile(
            new CommonMarkProfile(),
            [new IncludeDiscoveryExtension()],
        ));
        $this->parser = new SyntaxParser($profile);
        $includeKind = $profile->nodeKinds->find('include-discovery:block');
        if (null === $includeKind) {
            throw new \LogicException('Missing include discovery node kind.');
        }
        $this->includeKind = $includeKind->id;
    }

    public function expand(string $reference): string
    {
        return $this->expandRequest(
            new ResourceRequest($reference, 'include'),
            new IncludeExpansionSession(),
            1,
        );
    }

    private function expandRequest(
        ResourceRequest $request,
        IncludeExpansionSession $session,
        int $depth,
    ): string {
        if ($depth > $this->policy->maxDepth) {
            throw new ResourceDeniedException($request, \sprintf('the recursive include depth exceeds %d', $this->policy->maxDepth));
        }

        if ($session->resources >= $this->policy->maxResources) {
            throw new ResourceDeniedException($request, \sprintf('the include tree exceeds %d resources', $this->policy->maxResources));
        }

        $resource = $this->resolver->resolve($request);
        if (isset($session->active[$resource->id])) {
            throw new ResourceDeniedException($request, 'a recursive include cycle was detected');
        }

        ++$session->resources;
        $bytes = \strlen($resource->bytes);
        if ($bytes > $this->policy->maxExpandedBytes - $session->bytes) {
            throw new ResourceDeniedException($request, \sprintf('the include tree exceeds %d bytes', $this->policy->maxExpandedBytes));
        }
        $session->bytes += $bytes;
        $session->active[$resource->id] = true;

        try {
            return $this->expandResource($resource, $session, $depth);
        } finally {
            unset($session->active[$resource->id]);
        }
    }

    private function expandResource(
        ResolvedResource $resource,
        IncludeExpansionSession $session,
        int $depth,
    ): string {
        $syntax = $this->parser->parseFragment($resource->bytes, $this->policy->parseOptions());
        $tape = $syntax->tape();
        $replacements = [];
        $ordinal = $tape->firstChildOrdinal($syntax->rootOrdinal());

        while (ParseTape::NONE !== $ordinal) {
            if ($this->includeKind === $tape->kindId($ordinal)) {
                $reference = $tape->extensionBlockState($ordinal)->string('reference');
                $replacements[] = [
                    $tape->startOffset($ordinal),
                    $tape->endOffset($ordinal),
                    $this->expandRequest(
                        new ResourceRequest($reference, 'include', $resource->id),
                        $session,
                        $depth + 1,
                    ),
                ];
            }

            $ordinal = $tape->nextSiblingOrdinal($ordinal);
        }

        $expanded = $resource->bytes;

        for ($index = \count($replacements) - 1; $index >= 0; --$index) {
            [$start, $end, $replacement] = $replacements[$index];
            $expanded = substr($expanded, 0, $start).$replacement.substr($expanded, $end);
        }

        return $expanded;
    }
}
