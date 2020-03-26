# CoverService imports
This readme explains how-to install the imports used for CoverService.

Please note that the charts requires that the `shared-config` chart in the main repository have been executed to prepare
the cluster.

Install imports pod (which can be use to run importers manually) and the queue system to handle different async actions 
in the application.
```sh
helm upgrade --install cover-service-imports infrastructure/cover-service-importers/ --namespace cover-service
```

## Nightly import runs
Use the K8S cron jobs to run nightly imports of vendors. The `vendorName` in the command should be replaced by the name 
of the vendor that should be executed. 
```sh
helm upgrade --install cover-service-imports infrastructure/cover-service-cron-jobs/ --namespace cover-service --set cron.runAt="0 1 * * 1-6" --set vendorName=BogPortalen
```