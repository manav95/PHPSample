from flask import Flask, render_template, jsonify, request, redirect, url_for, send_from_directory, current_app, Response
import subprocess
import json
import os
import httplib2
import datetime
from datetime import date
from apiclient.discovery import build
from oauth2client.file import Storage
from oauth2client.client import AccessTokenRefreshError
from oauth2client.client import flow_from_clientsecrets
from oauth2client.tools import run
from pprint import pprint

app = Flask(__name__)
app.config['DEBUG'] = True
app.config['SECURITY_REGISTERABLE'] = True
app.config['SECURITY_CONFIRMABLE'] = False
CLIENT_SECRETS = 'client_secrets.json'
MISSING_CLIENT_SECRETS_MESSAGE = """
WARNING: Please configure OAuth 2.0
To make this sample run you will need to populate the client_secrets.json file
found at:
   %s
with information from the APIs Console <https://code.google.com/apis/console>.
""" % os.path.join(os.path.dirname(__file__), CLIENT_SECRETS)

# Set up a Flow object to be used if we need to authenticate.
FLOW = flow_from_clientsecrets(CLIENT_SECRETS,
  scope='https://www.googleapis.com/auth/prediction https://www.googleapis.com/auth/devstorage.read_only',
  message=MISSING_CLIENT_SECRETS_MESSAGE)
storage = Storage('prediction.dat')
credentials = storage.get()
if credentials is None or credentials.invalid:
   credentials = run(FLOW, storage)
http = httplib2.Http()
http = credentials.authorize(http)
service = build('prediction', 'v1.6', http=http)
x = service.trainedmodels()
PROJECT_ID = 'vaulted-botany-648'
dow_dict = { 'Monday' : 1,
             'Tuesday' : 2,
             'Wednesday' : 3,
             'Thursday' : 4,
             'Friday' : 5,
             'Saturday' : 6,
             'Sunday' : 7
             }
@app.route("/quantifyVenue", methods=["GET","POST"])
def quantifyVenue():
    if request.method == 'GET':
       dataArray = json.loads(request.json)
       orbitID = "83"
       startTime = dataArray[1]
       endTime = dataArray[2]
       if ((startTime != None) and (endTime != None)):
          if (not isinstance(startTime,(int, float, long, complex)) or not isinstance(endTime, (int, float, long, complex))):
             return jsonify(error="ORBIT_QUANTIFY_ERROR_INVALID_TIMESTAMP")
       if (orbitID == None):
             return jsonify(error="ORBIT_QUANTIFY_ERROR_INSUFFICIENT_INFO")
       startDate = datetime.date.fromtimestamp(startTime)
       dateArray = [startDate.weekday(), startDate.day, startDate.month]
       endDate = datetime.date.fromtimestamp(endTime)
       endDateArray = [endDate.weekday(), endDate.day, endDate.month]
       predList = []
       for daynum in range(dateArray[1], endDateArray[1] + 1):
           increment = daynum - dateArray[1]
           model = x.get(project=PROJECT_ID, id=orbitID).execute()
           if model['trainingStatus'] == 'DONE':
               body = {'input': {'csvInstance': [dateArray[0] + increment, daynum, dateArray[2],65,55,60]}}
               prediction = x.predict(project=PROJECT_ID,body=body,id=orbitID).execute()
               prediction = prediction['outputValue']
               currDate = date(2014, dateArray[2], daynum)
               print (prediction)
               predList.append(prediction)
       return json.dumps(predList)
@app.route("/quantifyBulkVenues", methods=["GET","POST"])
def quantifyBulkVenues():
    if request.method == 'GET':
           venueArray = json.loads(request.json)
           predDictionary = {}
           for venue in venueArray:
               orbitID = venue[0]
               startTime = venue[1]
               endTime = venue[2]
               if ((startTime != None) and (endTime != None)):
                  if (not isinstance(startTime,(int, float, long, complex)) or not isinstance(endTime, (int, float, long, complex))):
                     return jsonify(error="ORBIT_QUANTIFY_ERROR_INVALID_TIMESTAMP")
               if (orbitID == None):
                     return jsonify(error="ORBIT_QUANTIFY_ERROR_INSUFFICIENT_INFO")
               startDate = datetime.date.fromtimestamp(startTime)
               endDate = datetime.date.fromtimestamp(endTime)
               if (endDate-startDate).days < 0:
                   return json.dumps({"Error" : "End date occurs before start"})
               currDate = startDate
               predList = []
               while currDate != (endDate + datetime.timedelta(days=1)):
                   model = x.get(project=PROJECT_ID, id=orbitID).execute()
                   if model['trainingStatus'] == 'DONE':
                       body = {'input': {'csvInstance': [currDate.weekday(), currDate.day, currDate.month,65,55,60]}}
                       prediction = x.predict(project=PROJECT_ID,body=body,id=orbitID).execute()
                       prediction = prediction['outputValue']
                       currDate = date(2014, currDate.month, currDate.day)
                       predList.append(prediction)
                   currDate = currDate + datetime.timedelta(days=1)
               predDictionary[orbitID] = predList
           return json.dumps(predDictionary)
if __name__ == "__main__":
    app.run(debug=True, threaded = True, port=9992)
