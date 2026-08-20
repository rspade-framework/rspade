import React, { useContext } from 'react';
import ErrorOccurrenceContext from '../../../contexts/ErrorOccurrenceContext';
import { jsonStringify } from '../../../util';
import CodeSnippet from '../../ui/CodeSnippet';

export default function Files() {
    const errorOccurrence = useContext(ErrorOccurrenceContext);

    const files = errorOccurrence.context_items?.request_data?.files;

    if (!files) {
        return null;
    }

    return (
        <div className="col-span-2">
            <CodeSnippet value={jsonStringify(files)} />
        </div>
    );
}
