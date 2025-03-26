The Bibliography Module of the Liszt Portal
===========================================

[![TYPO3 11](https://img.shields.io/badge/TYPO3-11-orange.svg)](https://get.typo3.org/version/11)
[![CC-BY](https://img.shields.io/github/license/slub/liszt_bibliography)](https://github.com/slub/liszt_bibliography/blob/main/LICENSE)

This module fetches bibliographical entries from the Zotero API and stores them in a search engine index.
It provides a streamlined frontend plugin for browsing the bibliography.

# How to use the module

In the extension configuration, provide your Zotero API key, group and user id and the size of the bulk in which entries shall be retrieved from Zotero.
Define names for your indices and the bulksize for indexing.
Then, execute

    $ typo3 liszt_bibliography:index

The plugin may be included on a page using the New Content Element Wizard.

# Logging

When indexing, the module logs successful runs at info level and errors at error
levels. We recommand that you keep logs at those levels (see
[here](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Logging/Configuration/Index.html))
and check the logs frequently.

# Mapping collection IDs to item types

In the extension configuration, you can specify a mapping from collection ids to item types.
When indexing, the item type attribute of all items which belong to the specified collection is overridden.
In the extension configuration, u220se JSON syntax to specify the map:

    { "URC5G9EI" : "printedMusic" }

# Maintainers

If you have any questions or encounter any problems, please do not hesitate to contact us.
- [Matthias Richter](https://github.com/dikastes)
- [Thomas Schefter](https://github.com/thomas-sc)
