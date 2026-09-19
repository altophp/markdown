# Resources

Resource extensions resolve external content through an application-provided
`ResourceResolver`. Installing an extension never grants filesystem or network
authority by itself.

## Extensions

- [Import](resources/import.md): insert escaped source code.
- [Include](resources/include.md): expand bounded Markdown resources.
- [Source](resources/source.md): display annotated source excerpts.
- [Embeds](resources/embeds.md): resolve allowlisted rich content.

Set origin, size, recursion, and content-type limits in the resolver. Read
[Security](security.md) before enabling resource-backed behavior on untrusted
input, and [Errors](errors.md) for resolution failures.
