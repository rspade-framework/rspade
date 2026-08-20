import { flattenOnce } from '../util';
import { Flare } from '../types';

export default function getSolutions(
    solutionProviders: Array<Flare.SolutionProvider>,
    error: Error,
    extraSolutionParameters: Flare.SolutionProviderExtraParameters = {}
): Promise<Array<Flare.Solution>> {
    return new Promise(resolve => {
        const canSolves = solutionProviders.reduce(
            (canSolves, provider) => {
                canSolves.push(Promise.resolve(provider.canSolve(error, extraSolutionParameters)));

                return canSolves;
            },
            [] as Array<Promise<boolean>>
        );

        Promise.all(canSolves).then(resolvedCanSolves => {
            const solutionPromises: Array<Promise<Array<Flare.Solution>>> = [];

            resolvedCanSolves.forEach((canSolve, i) => {
                if (canSolve) {
                    solutionPromises.push(
                        Promise.resolve(solutionProviders[i].getSolutions(error, extraSolutionParameters))
                    );
                }
            });

            Promise.all(solutionPromises).then(solutions => {
                resolve(flattenOnce(solutions));
            });
        });
    });
}
