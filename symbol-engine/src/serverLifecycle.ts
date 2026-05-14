type CloseableApp = {
  close(): Promise<void>;
};

type CloseableDatabase = {
  end(): Promise<void>;
};

type Stoppable = {
  stop(): void;
};

export type ShutdownDependencies = {
  app: CloseableApp;
  db: CloseableDatabase;
  listener?: Stoppable | null;
  reconciler?: Stoppable | null;
};

export type ShutdownSignalDependencies = {
  shutdown: () => Promise<void>;
  logError: (error: unknown) => void;
  exit: (code: number) => void;
};

export async function shutdownSymbolEngine(dependencies: ShutdownDependencies): Promise<void> {
  dependencies.listener?.stop();
  dependencies.reconciler?.stop();

  try {
    await dependencies.app.close();
  } finally {
    await dependencies.db.end();
  }
}

export function handleShutdownSignal(dependencies: ShutdownSignalDependencies): void {
  void dependencies.shutdown()
    .then(() => dependencies.exit(0))
    .catch((error) => {
      dependencies.logError(error);
      dependencies.exit(1);
    });
}
