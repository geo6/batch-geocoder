import { library, dom } from '@fortawesome/fontawesome-svg-core'
import {
    faAngleDoubleRight,
    faArchive,
    faArrowCircleRight,
    faArrowRight,
    faCog,
    faDownload,
    faListUl,
    faMapMarkerAlt,
    faSave,
    faStar,
    faSync,
    faUpload,
    faUser
} from '@fortawesome/free-solid-svg-icons'

import {
    faCheckCircle,
    faEraser,
    faLanguage,
    faListAlt,
    faMap,
    faRocket,
    faStar as faStarEmpty,
    faTable,
    faTimesCircle,
} from '@fortawesome/pro-regular-svg-icons'

library.add(
    faAngleDoubleRight,
    faArchive,
    faArrowCircleRight,
    faArrowRight,
    faCheckCircle,
    faCog,
    faDownload,
    faEraser,
    faLanguage,
    faListAlt,
    faListUl,
    faMap,
    faMapMarkerAlt,
    faRocket,
    faSave,
    faStar,
    faStarEmpty,
    faSync,
    faTable,
    faTimesCircle,
    faUpload,
    faUser
);

dom.watch();
