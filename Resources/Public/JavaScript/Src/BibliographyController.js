/** todos
    *   fehlermeldungen
    *   ad hoc funktionen benennen
    *   facette entstehungsdatum
    *   t3-buildmechanismus für js
    *   facettenregistratur
    *   urlparameter auf highlighting abbilden
    */

const LISZT_BIB_DEFAULT_SIZE = 20;
const LISZT_BIB_DEFAULT_TRIGGER_POSITION = 200;
const LISZT_BIB_DEFAULT_BIBLIST_ID = 'bib-list';
const LISZT_BIB_DEFAULT_SIDELIST_ID = 'bib-list-side';
const LISZT_BIB_DEFAULT_BIBINDEX = 'zotero';
const LISZT_BIB_DEFAULT_LOCALEINDEX = 'zotero';

class BibliographyController {

    #urlManager = null;
    #target = '';
    #size = LISZT_BIB_DEFAULT_SIZE;
    #triggerPosition = LISZT_BIB_DEFAULT_TRIGGER_POSITION;
    #bibListId = LISZT_BIB_DEFAULT_BIBLIST_ID;
    #url = new URL(location);
    #body = {};

    constructor (config) {
        this.#target = config.target;
        this.#size = config.size ?? this.#size;
        this.#triggerPosition = config.triggerPosition ?? this.#triggerPosition;
        this.#bibListId = config.bibListId ?? this.#bibListId;

        this.init();
    }

    init() {
        this.#body = {};
        this.#urlManager = new UrlManager();
        this.#urlManager.registerMapping({itemTypes: 'query.match.itemType'});
        this.#body = this.#urlManager.body;

        this.client = new elasticsearch.Client({
            host: 'https://ddev-liszt-portal.ddev.site:9201'
        });
        this.from = 0;
        this.docs = this.client.search({ index: 'zotero', size: this.#size, body: this.#body });
        this.docs.then(docs => this.renderDocs(docs, this.#target));

        const types = this.client.search({
            index:'zotero',
            size:0,
            body:{
                aggs:{
                    types:{
                        categorize_text:{
                            field:'itemType'
                        }
                    }
                }
            }
        });
        types.then(r => this.renderTypes(r.aggregations.types.buckets));

        let allowed = true;
        $(window).scroll(_ => {
            const bottomPosition = $(document).scrollTop() + $(window).height()
            if (bottomPosition > $(document).height() - this.#triggerPosition && allowed) {
                allowed = false;
                this.from += this.#size;
                const newDocs = this.client.search({index:'zotero',size:this.#size,from:this.from,body:this.#body});
                newDocs.then(docs => {
                    this.appendDocs(docs, this.#target);
                    allowed = true;
                });
            }
        });
    }

    renderTypes(buckets) {
        const render = buckets => {
            const renderedBuckets = buckets
                .sort((a, b) => (b.doc_count - a.doc_count))
                .map(bucket => this.renderType(bucket, this.locales))
                .join('');
            const buttonGroup = `<div class="list-group list-group-flush">${renderedBuckets}</div>`;
            $(`#bib-list-side`).append(`<li id="item-type" class="list-group-item"><h4>${this.locales.fields.itemType}</h4></li>`);
            $(`#bib-list-side #item-type`).append(buttonGroup);
            $(`#bib-list-side .list-group-item-action`).click(d => {
                d.preventDefault();
                this.from = 0;
                $(d.target).toggleClass('active');
                const matches = $(`#bib-list-side .list-group-item.active`).map((d,i) => $(i).attr('data'));
                const itemTypes = matches.toArray().join(' ');

                this.#urlManager.setParam('itemTypes', itemTypes);
                this.#body = this.#urlManager.body;
                const docs = this.client.search({index:'zotero',size:this.#size,from:this.from,body:this.#body});
                docs.then(docs => {
                    this.renderDocs(docs, this.#target);
                });
            });
        }
        if (!this.locales) {
            this.client.get({index:'zoterolocales',id:'de'}).then(locales => {
                this.locales = locales._source;
                render(buckets);
            });
        } else {
            render(buckets);
        }
    }

    renderType(bucket, locales) {
        const key = locales.itemTypes[bucket.key];
        return `<a href="" class="list-group-item list-group-item-action" data="${bucket.key}"> ${key} (${bucket.doc_count}) </a>`;
    }

    appendDocs(docs, target) {
        const hits = docs.hits.hits.map(hit => hit._source);
        const renderedDocs = hits.map(hit => BibliographyController.renderDoc(hit, this.locales)).join('');
        $(renderedDocs).hide().appendTo(`#${target} #${this.#bibListId}`).fadeIn('slow');

    }

    renderDocs(docs, target) {
        const render = (docs, target) => {
            const hits = docs.hits.hits.map(hit => hit._source);
            const renderedDocs = hits.map(hit => BibliographyController.renderDoc(hit, this.locales)).join('');
            $(`#${target}`).html(`<ul class="list-group" id="${this.#bibListId}"> ${renderedDocs} </ul>`);
        }
        if (!this.locales) {
            this.client.get({index:'zoterolocales',id:'de'}).then(locales => {
                this.locales = locales._source;
                render(docs, target)
            });
        } else {
            render(docs, target);
        }
    }

    static renderDoc(doc, locales) {
        const itemType = locales.itemTypes[doc.itemType];
        const renderedCreators = doc.creators ? BibliographyController.renderCreators(doc.creators, locales) : '';
        return `<li class="list-group-item"> <h4> ${doc.title} <small class="pull-right"> ${itemType} </small> </h4> ${renderedCreators} </li>`;
    }

    static renderCreators(creators, locales) {
        return creators.map(creator => BibliographyController.renderCreator(creator, locales)).join(', ');
    }

    static renderCreator(creator, locales) {
        const creatorType = locales.creatorTypes[creator.creatorType];
        return `${creator.firstName} ${creator.lastName} (${creatorType})`;
    }
}
