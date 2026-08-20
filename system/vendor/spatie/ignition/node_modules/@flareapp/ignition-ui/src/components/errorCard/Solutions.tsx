import React, { useContext, useEffect, useState } from 'react';
import ErrorOccurrenceContext from '../../contexts/ErrorOccurrenceContext';
import Solution from './Solution';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faLightbulb, faTimes } from '@fortawesome/free-solid-svg-icons';

export default function Solutions() {
    const { solutions } = useContext(ErrorOccurrenceContext);
    const [canExecuteSolutions, setCanExecuteSolutions] = useState(false);
    const [showSolutions, setShowSolutions] = useState(true);

    useEffect(() => {
        try {
            (async () => {
                if (!solutions[0]?.execute_endpoint) {
                    return;
                }

                const healthCheck = await (
                    await fetch(solutions[0].execute_endpoint.replace('execute-solution', 'health-check'))
                ).json();

                setCanExecuteSolutions(healthCheck.can_execute_commands); // TODO: rename to can_execute_solutions (in laravel-ignition as well)
            })();
        } catch (error) {
            console.error(error);

            setCanExecuteSolutions(false);
        }
    }, []);

    return (
        <>
            {showSolutions ? (
                <aside id="solution" className="relative flex flex-col lg:w-2/5 flex-none">
                    <div className="flex-grow px-6 sm:px-10 py-8 bg-emerald-300 text-gray-800 rounded-bl-lg rounded-br-lg @4xl:rounded-bl-none @4xl:rounded-r-lg">
                        <button
                            onClick={() => setShowSolutions(false)}
                            className="absolute top-3 right-4 leading-none text-emerald-500 hover:text-emerald-700 text-sm"
                        >
                            <FontAwesomeIcon icon={faTimes} />
                        </button>

                        {solutions.map((solution, index) => (
                            <div key={index}>
                                <Solution
                                    solution={solution}
                                    canExecute={canExecuteSolutions}
                                    isOpen={index === 0}
                                    isCollapsible={solutions.length > 1}
                                />

                                {index !== solutions.length - 1 && <hr className="my-4 border-t border-gray-800/20" />}
                            </div>
                        ))}
                    </div>
                </aside>
            ) : (
                <button
                    onClick={() => setShowSolutions(true)}
                    className="
        absolute -top-3 -right-3 z-20
        w-6 h-6 rounded-full flex items-center justify-center
        text-xs bg-emerald-500 text-white hover:shadow-lg
        shadow-md
        active:shadow-sm active:translate-y-px"
                >
                    <FontAwesomeIcon icon={faLightbulb} />
                </button>
            )}
        </>
    );
}
