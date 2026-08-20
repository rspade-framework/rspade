import React, { useState } from 'react';
import { faRedoAlt, faWrench } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import Button from 'components/ui/Button';
import { ErrorSolution } from '../../types';
import SolutionDescription from './SolutionDescription';

type Props = {
    solution: ErrorSolution;
};

export default function SolutionRunner({ solution }: Props) {
    const [isRunningSolution, setIsRunningSolution] = useState(false);
    const [wasExecutionSuccessful, setWasExecutionSuccessful] = useState<boolean | null>(null);

    async function executeSolution() {
        if (isRunningSolution) {
            return;
        }

        try {
            setIsRunningSolution(true);

            if (!solution.execute_endpoint) {
                return;
            }

            const response = await fetch(solution.execute_endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ solution: solution.class, parameters: solution.run_parameters }),
            });

            setWasExecutionSuccessful(response.status >= 200 && response.status < 300);
        } catch (error) {
            console.error(error);
            setWasExecutionSuccessful(false);
        } finally {
            setIsRunningSolution(false);
        }
    }

    function refresh(event: React.MouseEvent<HTMLAnchorElement, MouseEvent>) {
        event.preventDefault();
        location.reload();
    }

    return (
        <>
            {wasExecutionSuccessful === null && (
                <>
                    <Button
                        onClick={executeSolution}
                        disabled={isRunningSolution}
                        className="mb-4 inline-flex items-center gap-2 bg-emerald-600 border-emerald-500/25 text-white"
                    >
                        {isRunningSolution ? (
                            <span>Running...</span>
                        ) : (
                            <>
                                <FontAwesomeIcon className="opacity-50" icon={faWrench} />
                                {solution.run_button_text || 'Run'}
                            </>
                        )}
                    </Button>

                    <SolutionDescription solution={solution} />
                </>
            )}

            {wasExecutionSuccessful === true && (
                <p className="">
                    The solution was executed <strong>successfully</strong>.
                    <br />
                    <a
                        href="#"
                        className="mt-2 inline-flex items-center gap-2 underline text-emerald-700 hover:text-emerald-800"
                        onClick={refresh}
                    >
                        <FontAwesomeIcon icon={faRedoAlt} className="text-sm opacity-50" />
                        Refresh now
                    </a>
                </p>
            )}

            {wasExecutionSuccessful === false && (
                <>
                    <p className="bg-red-200 px-4 py-2">
                        Something <strong>went wrong</strong>. Please try refreshing the page and try again.
                        <br />
                        <a
                            href="#"
                            className="mt-2 inline-flex items-center gap-2 underline text-red-700 hover:text-red-800"
                            onClick={refresh}
                        >
                            <FontAwesomeIcon icon={faRedoAlt} className="text-sm opacity-50" />
                            Refresh now
                        </a>
                    </p>
                </>
            )}
        </>
    );
}
