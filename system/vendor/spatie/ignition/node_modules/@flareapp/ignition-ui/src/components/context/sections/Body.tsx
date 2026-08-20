import React, { useContext } from 'react';
import ErrorOccurrenceContext from '../../../contexts/ErrorOccurrenceContext';
import { jsonStringify } from '../../../util';
import CodeSnippet from '../../ui/CodeSnippet';

export default function Body() {
    const errorOccurrence = useContext(ErrorOccurrenceContext);

    const body = errorOccurrence.context_items?.request_data?.body;

    if (!body) {
        return null;
    }

    return <CodeSnippet value={jsonStringify(body)} />;
}
