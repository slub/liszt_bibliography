The Bibliography Module of the Liszt Portal
===========================================

[![TYPO3 11](https://img.shields.io/badge/TYPO3-11-orange.svg)](https://get.typo3.org/version/11)
[![CC-BY](https://img.shields.io/github/license/dikastes/liszt_bibliography)](https://github.com/dikastes/liszt_bibliography/blob/main/LICENSE)

This module fetches bibliographical entries from the Zotero API and stores them in a search engine index.
It provides a streamlined frontend plugin for browsing the bibliography.

# How to use the module

In the extension configuration, provide your Zotero API key, group and user id and the size of the bulk in which entries shall be retrieved from Zotero.
Define names for your indices and the bulksize for indexing.
Then, execute

    $ typo3 liszt_bibliography:index

The plugin may be included on a page using the New Content Element Wizard.
