<?php
declare(strict_types=1);

/**
 * Stoplist per l'estrazione delle keyword in item.php (funzione extract_keywords).
 *
 * Scopo: escludere dalle "parole piu' frequenti" tutte le particelle
 * grammaticali e le parole funzionali (articoli, preposizioni, pronomi,
 * congiunzioni, ausiliari, avverbi generici, verbi svuotati) che non sono
 * veri sostantivi/keyword, anche quando compaiono spessissimo.
 *
 * Copertura: inglese + italiano, con e senza accenti, piu' un po' di "rumore"
 * tipico dei testi estratti (page, cookie, subscribe, ...). NON inserire qui
 * parole di contenuto potenzialmente informative (paesi, ruoli, temi):
 * es. "government", "military", "sanctions" restano keyword valide.
 *
 * Le voci con meno di 4 caratteri sono comunque gia' scartate a monte dal
 * filtro di lunghezza in extract_keywords(); qui restano solo per chiarezza.
 */

return [
    // ---------------------------------------------------------------- EN core
    'the', 'a', 'an', 'and', 'or', 'nor', 'but', 'yet', 'so', 'for', 'of',
    'to', 'in', 'on', 'at', 'by', 'up', 'as', 'if', 'is', 'it', 'be', 'do',
    'no', 'not', 'off', 'out', 'own', 'too', 'via', 'per', 'etc', 'vs',
    'this', 'that', 'these', 'those', 'there', 'here', 'then', 'than', 'thus',
    'hence', 'such', 'same', 'some', 'any', 'all', 'both', 'each', 'few',
    'more', 'most', 'much', 'many', 'other', 'others', 'another', 'only',
    'very', 'just', 'also', 'even', 'ever', 'never', 'always', 'often',
    'sometimes', 'usually', 'again', 'once', 'still', 'already', 'almost',
    'enough', 'indeed', 'perhaps', 'maybe', 'rather', 'quite', 'somewhat',
    'however', 'moreover', 'furthermore', 'nevertheless', 'nonetheless',
    'therefore', 'thereby', 'thereof', 'herein', 'hereby', 'whereby',
    'wherein', 'regardless', 'accordingly', 'additionally', 'similarly',
    'likewise', 'otherwise', 'instead', 'anyway', 'anyhow', 'somehow',
    'certain', 'certainly', 'particular', 'particularly', 'especially',
    'specifically', 'generally', 'basically', 'essentially', 'actually',
    'currently', 'recently', 'previously', 'finally', 'eventually',
    'initially', 'overall', 'meanwhile', 'namely', 'notably',
    'about', 'above', 'below', 'under', 'over', 'across', 'after', 'before',
    'since', 'until', 'till', 'while', 'during', 'within', 'without', 'into',
    'onto', 'upon', 'against', 'among', 'amongst', 'amid', 'amidst',
    'between', 'through', 'throughout', 'toward', 'towards', 'behind',
    'beyond', 'beside', 'besides', 'near', 'around', 'along', 'past',
    'down', 'from', 'with', 'this',

    // ---------------------------------------------------------------- EN pronouns
    'i', 'me', 'my', 'mine', 'myself', 'we', 'us', 'our', 'ours', 'ourselves',
    'you', 'your', 'yours', 'yourself', 'yourselves', 'he', 'him', 'his',
    'himself', 'she', 'her', 'hers', 'herself', 'it', 'its', 'itself',
    'they', 'them', 'their', 'theirs', 'themselves', 'who', 'whom', 'whose',
    'which', 'what', 'whatever', 'whoever', 'whomever', 'whichever',
    'where', 'when', 'why', 'how', 'wherever', 'whenever', 'however',
    'anyone', 'anybody', 'anything', 'someone', 'somebody', 'something',
    'everyone', 'everybody', 'everything', 'nobody', 'nothing', 'none',

    // ---------------------------------------------------------------- EN verbs (svuotati / ausiliari / modali)
    'am', 'are', 'was', 'were', 'been', 'being', 'have', 'has', 'had',
    'having', 'does', 'did', 'doing', 'done', 'can', 'could', 'shall',
    'should', 'will', 'would', 'may', 'might', 'must', 'cannot', 'cant',
    'wont', 'dont', 'doesnt', 'didnt', 'isnt', 'arent', 'wasnt', 'werent',
    'hasnt', 'havent', 'hadnt', 'wouldnt', 'couldnt', 'shouldnt',
    'get', 'gets', 'got', 'gotten', 'getting', 'go', 'goes', 'going',
    'gone', 'went', 'come', 'comes', 'coming', 'came', 'make', 'makes',
    'made', 'making', 'take', 'takes', 'taking', 'took', 'taken', 'give',
    'gives', 'giving', 'gave', 'given', 'put', 'puts', 'putting',
    'see', 'sees', 'seeing', 'saw', 'seen', 'look', 'looks', 'looking',
    'looked', 'know', 'knows', 'knowing', 'knew', 'known', 'think',
    'thinks', 'thinking', 'thought', 'want', 'wants', 'wanted', 'wanting',
    'use', 'uses', 'used', 'using', 'need', 'needs', 'needed', 'seem',
    'seems', 'seemed', 'become', 'becomes', 'became', 'becoming',
    'say', 'says', 'said', 'saying', 'tell', 'tells', 'told', 'telling',
    'ask', 'asks', 'asked', 'call', 'calls', 'called', 'calling',
    'let', 'lets', 'try', 'tries', 'tried', 'trying', 'keep', 'keeps',
    'kept', 'help', 'helps', 'helped', 'show', 'shows', 'showed', 'shown',

    // ---------------------------------------------------------------- EN filler / rumore giornalistico
    'according', 'reported', 'reports', 'report', 'reporting', 'added',
    'noted', 'including', 'include', 'includes', 'included', 'like',
    'unlike', 'well', 'back', 'thing', 'things', 'way', 'ways', 'lot',
    'lots', 'people', 'part', 'parts', 'kind', 'sort', 'stuff', 'bit',
    'today', 'yesterday', 'tomorrow', 'year', 'years', 'day', 'days',
    'week', 'weeks', 'month', 'months', 'hour', 'hours', 'minute',
    'minutes', 'time', 'times', 'moment', 'number', 'numbers', 'case',
    'cases', 'point', 'points', 'fact', 'facts', 'group', 'groups',
    'area', 'areas', 'level', 'levels', 'end', 'ends', 'start', 'starts',
    'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight',
    'nine', 'ten', 'first', 'second', 'third', 'last', 'next', 'new',
    'old', 'good', 'great', 'big', 'small', 'long', 'high', 'low',
    'many', 'several', 'various',
    'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday',
    'sunday', 'january', 'february', 'march', 'april', 'june', 'july',
    'august', 'september', 'october', 'november', 'december',

    // ---------------------------------------------------------------- EN web boilerplate
    'cookie', 'cookies', 'subscribe', 'subscription', 'newsletter',
    'advertisement', 'advertising', 'sponsored', 'share', 'comment',
    'comments', 'reply', 'email', 'sign', 'login', 'register', 'password',
    'menu', 'search', 'home', 'click', 'read', 'more', 'view', 'follow',
    'copyright', 'reserved', 'rights', 'terms', 'privacy', 'policy',
    'contact', 'about', 'page', 'pages', 'website', 'site', 'link',
    'links', 'image', 'images', 'photo', 'photos', 'video', 'videos',
    'caption', 'source', 'file', 'files', 'document', 'documents', 'scan',
    'date', 'update', 'updated', 'published', 'posted',

    // ---------------------------------------------------------------- IT core
    'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'un\'',
    'di', 'a', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra',
    'del', 'dello', 'della', 'dei', 'degli', 'delle', 'dell',
    'dal', 'dallo', 'dalla', 'dai', 'dagli', 'dalle', 'dall',
    'al', 'allo', 'alla', 'ai', 'agli', 'alle', 'all',
    'nel', 'nello', 'nella', 'nei', 'negli', 'nelle', 'nell',
    'sul', 'sullo', 'sulla', 'sui', 'sugli', 'sulle', 'sull',
    'col', 'coi', 'collo', 'colla',
    'e', 'ed', 'o', 'od', 'ma', 'se', 'che', 'chi', 'cui', 'ne', 'ce',
    'come', 'dove', 'quando', 'quanto', 'quanti', 'quante', 'quanta',
    'perche', 'perché', 'poiche', 'poiché', 'mentre', 'anche', 'ancora',
    'gia', 'già', 'non', 'ne', 'né', 'si', 'sì', 'piu', 'più', 'meno',
    'molto', 'molti', 'molte', 'molta', 'poco', 'pochi', 'poche', 'poca',
    'tanto', 'tanti', 'tante', 'tanta', 'troppo', 'troppi', 'troppe',
    'tutto', 'tutti', 'tutta', 'tutte', 'ogni', 'ognuno', 'qualche',
    'alcuni', 'alcune', 'alcuno', 'nessun', 'nessuno', 'nessuna',

    // ---------------------------------------------------------------- IT dimostrativi / pronomi
    'questo', 'questa', 'questi', 'queste', 'quest',
    'quello', 'quella', 'quelli', 'quelle', 'quel', 'quegli',
    'codesto', 'codesta', 'stesso', 'stessa', 'stessi', 'stesse',
    'esso', 'essa', 'essi', 'esse', 'egli', 'ella', 'lui', 'lei', 'loro',
    'noi', 'voi', 'io', 'tu', 'ci', 'vi', 'mi', 'ti', 'gli', 'le',
    'mio', 'mia', 'miei', 'mie', 'tuo', 'tua', 'tuoi', 'tue',
    'suo', 'sua', 'suoi', 'sue', 'nostro', 'nostra', 'nostri', 'nostre',
    'vostro', 'vostra', 'vostri', 'vostre',
    'quale', 'quali', 'tale', 'tali', 'altro', 'altra', 'altri', 'altre',
    'altrui', 'medesimo', 'ciascuno', 'ciascuna',

    // ---------------------------------------------------------------- IT verbi (essere / avere / fare / modali)
    'essere', 'sono', 'sei', 'e', 'è', 'siamo', 'siete', 'era', 'erano',
    'eri', 'ero', 'eravamo', 'eravate', 'saro', 'sarò', 'sara', 'sarà',
    'saranno', 'sarei', 'sarebbe', 'sarebbero', 'stato', 'stata', 'stati',
    'state', 'essendo', 'sia', 'siano',
    'avere', 'ho', 'hai', 'ha', 'abbiamo', 'avete', 'hanno', 'aveva',
    'avevano', 'avevo', 'avevi', 'avuto', 'avuta', 'avuti', 'avute',
    'avra', 'avrà', 'avranno', 'avrei', 'avrebbe', 'abbia', 'abbiano',
    'fare', 'fa', 'fanno', 'faccio', 'fai', 'facciamo', 'fate', 'faceva',
    'facevano', 'fatto', 'fatta', 'fatti', 'fatte', 'fara', 'farà',
    'potere', 'puo', 'può', 'posso', 'puoi', 'possiamo', 'potete',
    'possono', 'poteva', 'potevano', 'potuto', 'potra', 'potrà',
    'dovere', 'deve', 'devo', 'devi', 'dobbiamo', 'dovete', 'devono',
    'doveva', 'dovevano', 'dovuto', 'dovra', 'dovrà',
    'volere', 'vuole', 'voglio', 'vuoi', 'vogliamo', 'volete', 'vogliono',
    'voleva', 'volevano', 'voluto',
    'dire', 'dice', 'dico', 'dici', 'diciamo', 'dite', 'dicono', 'diceva',
    'dicevano', 'detto', 'detta', 'detti', 'dette',
    'andare', 'va', 'vado', 'vai', 'andiamo', 'andate', 'vanno', 'andava',
    'andato', 'andata', 'venire', 'viene', 'vengo', 'vieni', 'venuto',
    'stare', 'sto', 'stai', 'sta', 'stiamo', 'stanno', 'stava', 'stavano',
    'stando', 'stia', 'stiano', 'stesse', 'stessero',

    // ---------------------------------------------------------------- IT preposizioni/avverbi
    'dopo', 'prima', 'durante', 'contro', 'senza', 'verso', 'presso',
    'oltre', 'sotto', 'sopra', 'dentro', 'fuori', 'lungo', 'circa',
    'secondo', 'tramite', 'mediante', 'nonostante', 'malgrado',
    'qui', 'qua', 'li', 'lì', 'la', 'là', 'ora', 'adesso', 'poi',
    'quindi', 'dunque', 'pero', 'però', 'invece', 'inoltre', 'tuttavia',
    'anzi', 'infatti', 'cioe', 'cioè', 'ossia', 'ovvero', 'insomma',
    'comunque', 'allora', 'sempre', 'mai', 'spesso', 'talvolta', 'subito',
    'ancora', 'appena', 'quasi', 'proprio', 'davvero', 'forse', 'magari',
    'piuttosto', 'abbastanza', 'molto', 'assai', 'ecco', 'ecc',
    'cosi', 'così', 'certo', 'certa', 'certi', 'certe', 'pure', 'perfino',
    'persino', 'addirittura', 'soprattutto', 'specialmente', 'particolarmente',
    'generalmente', 'praticamente', 'sostanzialmente', 'effettivamente',
    'attualmente', 'recentemente', 'ultimamente', 'finora', 'intanto',
    'frattempo', 'nonche', 'nonché', 'bensi', 'bensì', 'sebbene', 'benche',
    'benché', 'affinche', 'affinché', 'purche', 'purché', 'qualora',
    'laddove', 'ovunque', 'dovunque', 'chiunque', 'qualunque', 'qualsiasi',
    'chissa', 'chissà', 'oppure', 'eppure', 'ormai', 'almeno', 'perlomeno',
    'soltanto', 'solamente', 'solo', 'appunto', 'semmai', 'nemmeno',
    'neppure', 'neanche', 'affatto', 'mica',

    // ---------------------------------------------------------------- IT filler / rumore
    'cosa', 'cose', 'modo', 'modi', 'parte', 'parti', 'punto', 'punti',
    'fatto', 'caso', 'casi', 'volta', 'volte', 'anno', 'anni', 'giorno',
    'giorni', 'mese', 'mesi', 'settimana', 'settimane', 'ora', 'ore',
    'oggi', 'ieri', 'domani', 'gente', 'persone', 'numero', 'numeri',
    'gruppo', 'gruppi', 'zona', 'zone', 'livello', 'primo', 'prima',
    'secondo', 'terzo', 'ultimo', 'nuovo', 'nuova', 'vecchio', 'grande',
    'piccolo', 'due', 'tre', 'quattro', 'cinque',
    'lunedi', 'lunedì', 'martedi', 'martedì', 'mercoledi', 'mercoledì',
    'giovedi', 'giovedì', 'venerdi', 'venerdì', 'sabato', 'domenica',
    'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio',
    'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre',
];
