\echo Loading fasting protocol definitions into staging

CREATE TABLE IF NOT EXISTS fasting_staging.protocol_defs(
    file_name TEXT PRIMARY KEY,
    def_name TEXT NOT NULL,
    definition JSONB NOT NULL
);

DELETE FROM fasting_staging.protocol_defs;

INSERT INTO fasting_staging.protocol_defs (file_name, def_name, definition) VALUES
('Baseline.hpm.v1.json', 'Baseline', $ssdef$
{
  "schemaVersion": 1,
  "name": "Baseline",
  "initialState": "initial",
  "timers": ["startWarnDeadline", "endWarnDeadline", "timeout24Hours"],
  "settings": {
    "endProtocolEvent": "receivedEndProtocol",
    "collection": "none"
  },
  "restore": {
    "policy": "same-day-else-reset",
    "terminalStates": ["endProtocol"],
    "fallbackState": "waitStart",
    "noSaveStateEvent": "receivedWaitStart",
    "dayBoundaryHour": 4,
    "dayBoundaryMinute": 5,
    "replay": {
      "waitStart": {"restoring": ["receivedWaitStart"]},
      "warnStartCal": {"restoring": ["receivedWarnStart"]},
      "startcal": {"restoring": ["receivedStartCal"]},
      "warnEndCal": {"restoring": ["receivedWarnEndCal"]},
      "endcal": {"restoring": ["receivedStartCal", "receivedEndCal"]}
    },
    "resetFire": "receivedWaitStart"
  },
  "eventMappings": {
    "sms": {
      "defaultAction": "ignore",
      "ignoreKeywords": ["snack"],
      "fallbackReply": "Your text was not understood. Please send \"STARTCAL\" when you begin calories for the day; \"ENDCAL\" when you are done with calories for the day.",
      "rules": [
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["waitStart", "warnStartCal"],
          "condition": {"type": "fieldWasYesterday", "field": "start_cal_time", "boundaryHour": 4, "boundaryMinute": 0},
          "notifyAdmins": "[{study} Baseline] A participant sent ENDCAL after 4am and before STARTCAL.",
          "flagLastMessage": "isAfter8pm"
        },
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["waitStart", "warnStartCal"],
          "reply": "No \"STARTCAL\" received yet today. Please send us your \"STARTCAL\" so we know when your calories began today."
        },
        {
          "keywords": ["startcal"],
          "inStates": ["waitStart", "warnStartCal", "startcal"],
          "parseTime": true,
          "saveAs": "start_cal_time",
          "maxBareLength": 8,
          "fire": "receivedStartCal",
          "parseErrorReply": "Your STARTCAL time was not understood. Please send \"STARTCAL\" again with your starting time. For example, \"STARTCAL 7:30 am\"."
        },
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["startcal", "warnEndCal", "endcal"],
          "parseTime": true,
          "saveAs": "end_cal_time",
          "maxBareLength": 7,
          "fire": "receivedEndCal",
          "parseErrorReply": "Your ENDCAL time was not understood. Please send \"ENDCAL\" again with your ending time. For example, \"ENDCAL 7:30 pm\"."
        },
        {
          "keywords": ["startcal"],
          "inStates": ["warnEndCal", "endcal"],
          "reply": "Your text was not understood. Text {admin_phone} if you need help."
        }
      ]
    }
  },
  "states": {
    "initial": {
      "transitions": [
        {"event": "receivedWaitStart", "to": "waitStart"},
        {"event": "receivedWarnStart", "to": "warnStartCal"},
        {"event": "receivedStartCal", "to": "startcal"},
        {"event": "receivedWarnEndCal", "to": "warnEndCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "waitStart", "via": "receivedWaitStart"},
        {"to": "warnStartCal", "via": "receivedWarnStart"},
        {"to": "startcal", "via": "receivedStartCal"},
        {"to": "warnEndCal", "via": "receivedWarnEndCal"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "waitStart": {
      "entry": [
        {"type": "clearVars", "keys": ["sentMissedEndCal"]},
        {
          "type": "setTimer",
          "timer": "startWarnDeadline",
          "deadline": {
            "type": "secondsToClock",
            "hour": 11,
            "minute": 59,
            "second": 30,
            "ifPastAddDay": false,
            "addDayIf": {"type": "afterClock", "hour": 16, "minute": 45},
            "minSeconds": 300
          }
        }
      ],
      "transitions": [
        {"event": "receivedStartCal", "to": "startcal"},
        {"after": "startWarnDeadline", "to": "warnStartCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "warnStartCal", "via": "timeout:startWarnDeadline"},
        {"to": "startcal", "via": "receivedStartCal", "timeField": "start_cal_time"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "warnStartCal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "timeout24Hours",
          "deadline": {"type": "secondsToClock", "hour": 3, "minute": 59, "second": 30, "ifPastAddDay": true}
        },
        {"type": "sendSms", "template": "Remember to text \"STARTCAL\" when your calories start for the day and \"ENDCAL\" when your calories finish at night. Thank you!"}
      ],
      "transitions": [
        {"event": "receivedStartCal", "to": "startcal"},
        {"after": "timeout24Hours", "to": "timeout24"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "timeout24", "via": "timeout:timeout24Hours"},
        {"to": "startcal", "via": "receivedStartCal", "timeField": "start_cal_time"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "startcal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "endWarnDeadline",
          "deadline": {"type": "secondsToClock", "hour": 20, "minute": 59, "second": 30, "ifPastAddDay": false, "minSeconds": 300}
        }
      ],
      "transitions": [
        {"event": "receivedStartCal", "to": "startcal"},
        {"event": "receivedEndCal", "to": "endcal"},
        {"after": "endWarnDeadline", "to": "warnEndCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "startcal", "via": "receivedStartCal", "timeField": "start_cal_time"},
        {"to": "endcal", "via": "receivedEndCal", "timeField": "end_cal_time"},
        {"to": "warnEndCal", "via": "timeout:endWarnDeadline"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "warnEndCal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "timeout24Hours",
          "deadline": {"type": "secondsToClock", "hour": 3, "minute": 59, "second": 30, "ifPastAddDay": true}
        },
        {"type": "sendSms", "template": "Remember to enter your \"ENDCAL\" tonight after your last calories. Thank you!"}
      ],
      "transitions": [
        {"event": "receivedEndCal", "to": "endcal"},
        {"after": "timeout24Hours", "to": "missedEndCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "missedEndCal", "via": "timeout:timeout24Hours"},
        {"to": "endcal", "via": "receivedEndCal"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "missedEndCal": {
      "entry": [
        {
          "type": "notifyAdmins",
          "template": "[{study} Baseline] A participant missed their ENDCAL.",
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"},
          "suppressOnRestore": true
        },
        {"type": "setVar", "key": "sentMissedEndCal", "value": "true"},
        {
          "type": "sendSms",
          "template": "We haven't heard from you in a while. Remember to text \"STARTCAL\" when your calories start in the morning and \"ENDCAL\" when your calories finish at night! Let us know if you need help.",
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "participant"}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} Baseline] A participant did not send STARTCAL or ENDCAL yesterday.",
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"},
          "suppressOnRestore": true,
          "suppressIf": {"type": "varEquals", "key": "sentMissedEndCal", "value": "true"}
        }
      ],
      "transitions": [{"auto": true, "to": "waitStart"}],
      "manualMoves": []
    },
    "endcal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "timeout24Hours",
          "deadline": {"type": "secondsToClock", "hour": 3, "minute": 59, "second": 30, "ifPastAddDay": true}
        }
      ],
      "transitions": [
        {"event": "receivedEndCal", "to": "endcal"},
        {"after": "timeout24Hours", "to": "waitStart"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "endcal", "via": "receivedEndCal", "timeField": "end_cal_time"},
        {"to": "waitStart", "via": "timeout:timeout24Hours"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "timeout24": {
      "entry": [
        {
          "type": "sendSms",
          "template": "We haven't heard from you in a while. Remember to text \"STARTCAL\" when your calories start in the morning and \"ENDCAL\" when your calories finish at night! Let us know if you need help.",
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "participant"}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} Baseline] A participant did not send STARTCAL or ENDCAL yesterday.",
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"},
          "suppressOnRestore": true,
          "suppressIf": {"type": "varEquals", "key": "sentMissedEndCal", "value": "true"}
        }
      ],
      "transitions": [{"auto": true, "to": "waitStart"}],
      "manualMoves": [
        {"to": "waitStart", "via": "auto"}
      ]
    },
    "endProtocol": {
      "terminal": true,
      "transitions": [],
      "manualMoves": []
    }
  }
}
$ssdef$::jsonb);

INSERT INTO fasting_staging.protocol_defs (file_name, def_name, definition) VALUES
('Baseline.v1.json', 'Baseline', $ssdef$
{
  "schemaVersion": 1,
  "name": "Baseline",
  "initialState": "initial",
  "timers": ["startWarnDeadline", "endWarnDeadline", "timeout24Hours", "timeout8pm"],
  "settings": {
    "endProtocolEvent": "receivedEndProtocol",
    "collection": "none"
  },
  "restore": {
    "policy": "same-day-else-reset",
    "terminalStates": ["endProtocol"],
    "fallbackState": "waitStart",
    "noSaveStateEvent": "receivedWaitStart",
    "dayBoundaryHour": 4,
    "dayBoundaryMinute": 5,
    "replay": {
      "waitStart": {"restoring": ["receivedWaitStart"]},
      "warnStartCal": {"restoring": ["receivedWarnStart"]},
      "startcal": {"restoring": ["receivedStartCal"]},
      "warnEndCal": {"restoring": ["receivedWarnEndCal"]},
      "endcal": {"restoring": ["receivedStartCal", "receivedEndCal"]}
    },
    "resetFire": "receivedWaitStart"
  },
  "eventMappings": {
    "sms": {
      "defaultAction": "ignore",
      "ignoreKeywords": ["snack"],
      "fallbackReply": "Your text was not understood. Please send \"STARTCAL\" when you begin calories for the day; \"ENDCAL\" when you are done with calories for the day.",
      "rules": [
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["waitStart", "warnStartCal"],
          "condition": {"type": "fieldWasYesterday", "field": "start_cal_time", "boundaryHour": 4, "boundaryMinute": 0},
          "notifyAdmins": "[{study} Baseline] A participant sent ENDCAL after 4am and before STARTCAL.",
          "flagLastMessage": "isAfter8pm"
        },
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["waitStart", "warnStartCal"],
          "reply": "No STARTCAL received yet today. Please send us your \"STARTCAL\" so we know when your calories began today."
        },
        {
          "keywords": ["startcal"],
          "inStates": ["waitStart", "warnStartCal", "startcal"],
          "parseTime": true,
          "saveAs": "start_cal_time",
          "maxBareLength": 8,
          "fire": "receivedStartCal",
          "parseErrorReply": "Your STARTCAL time was not understood. Please send \"STARTCAL\" again with your starting time. For example, \"STARTCAL 7:30 am\"."
        },
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["startcal", "warnEndCal", "endcal"],
          "parseTime": true,
          "saveAs": "end_cal_time",
          "maxBareLength": 7,
          "fire": "receivedEndCal",
          "parseErrorReply": "Your ENDCAL time was not understood. Please send \"ENDCAL\" again with your ending time. For example, \"ENDCAL 7:30 pm\"."
        },
        {
          "keywords": ["startcal"],
          "inStates": ["warnEndCal", "endcal"],
          "reply": "Your text was not understood. Text {admin_phone} if you need help."
        }
      ]
    }
  },
  "states": {
    "initial": {
      "transitions": [
        {"event": "receivedWaitStart", "to": "waitStart"},
        {"event": "receivedWarnStart", "to": "warnStartCal"},
        {"event": "receivedStartCal", "to": "startcal"},
        {"event": "receivedWarnEndCal", "to": "warnEndCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "waitStart", "via": "receivedWaitStart"},
        {"to": "warnStartCal", "via": "receivedWarnStart"},
        {"to": "startcal", "via": "receivedStartCal"},
        {"to": "warnEndCal", "via": "receivedWarnEndCal"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "waitStart": {
      "entry": [
        {"type": "clearVars", "keys": ["sentMissedEndCal"]},
        {
          "type": "setTimer",
          "timer": "startWarnDeadline",
          "deadline": {
            "type": "secondsToClock",
            "hour": 11,
            "minute": 59,
            "second": 30,
            "ifPastAddDay": false,
            "addDayIf": {"type": "afterClock", "hour": 16, "minute": 45},
            "minSeconds": 300
          }
        }
      ],
      "transitions": [
        {"event": "receivedStartCal", "to": "startcal"},
        {"after": "startWarnDeadline", "to": "warnStartCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "warnStartCal", "via": "timeout:startWarnDeadline"},
        {"to": "startcal", "via": "receivedStartCal", "timeField": "start_cal_time"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "warnStartCal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "timeout8pm",
          "deadline": {"type": "secondsToClock", "hour": 20, "minute": 0, "ifPastAddDay": true}
        },
        {
          "type": "setTimer",
          "timer": "timeout24Hours",
          "deadline": {"type": "secondsToClock", "hour": 3, "minute": 59, "second": 30, "ifPastAddDay": true}
        },
        {"type": "sendSms", "template": "Remember to text \"STARTCAL\" when your calories start for the day and \"ENDCAL\" when your calories finish at night. Thank you!"}
      ],
      "transitions": [
        {"event": "receivedStartCal", "to": "startcal"},
        {"after": "timeout8pm", "to": "warnStartCal"},
        {"after": "timeout24Hours", "to": "timeout24"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "timeout24", "via": "timeout:timeout24Hours"},
        {"to": "warnStartCal", "via": "timeout:timeout8pm"},
        {"to": "startcal", "via": "receivedStartCal", "timeField": "start_cal_time"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "startcal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "endWarnDeadline",
          "deadline": {"type": "secondsToClock", "hour": 20, "minute": 59, "second": 30, "ifPastAddDay": false, "minSeconds": 300}
        }
      ],
      "transitions": [
        {"event": "receivedStartCal", "to": "startcal"},
        {"event": "receivedEndCal", "to": "endcal"},
        {"after": "endWarnDeadline", "to": "warnEndCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "startcal", "via": "receivedStartCal", "timeField": "start_cal_time"},
        {"to": "endcal", "via": "receivedEndCal", "timeField": "end_cal_time"},
        {"to": "warnEndCal", "via": "timeout:endWarnDeadline"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "warnEndCal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "timeout24Hours",
          "deadline": {"type": "secondsToClock", "hour": 3, "minute": 59, "second": 30, "ifPastAddDay": true}
        },
        {"type": "sendSms", "template": "Remember to enter your \"ENDCAL\" tonight after your last calories. Thank you!"}
      ],
      "transitions": [
        {"event": "receivedEndCal", "to": "endcal"},
        {"after": "timeout24Hours", "to": "missedEndCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "missedEndCal", "via": "timeout:timeout24Hours"},
        {"to": "endcal", "via": "receivedEndCal"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "missedEndCal": {
      "entry": [
        {
          "type": "notifyAdmins",
          "template": "[{study} Baseline] A participant missed their ENDCAL.",
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"},
          "suppressOnRestore": true
        },
        {"type": "setVar", "key": "sentMissedEndCal", "value": "true"},
        {
          "type": "sendSms",
          "template": "We haven't heard from you in a while. Remember to text \"STARTCAL\" when your calories start in the morning and \"ENDCAL\" when your calories finish at night! Let us know if you need help.",
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "participant"}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} Baseline] A participant did not send STARTCAL or ENDCAL yesterday.",
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"},
          "suppressOnRestore": true
        }
      ],
      "transitions": [{"auto": true, "to": "waitStart"}],
      "manualMoves": []
    },
    "endcal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "timeout24Hours",
          "deadline": {"type": "secondsToClock", "hour": 3, "minute": 59, "second": 30, "ifPastAddDay": true}
        }
      ],
      "transitions": [
        {"event": "receivedEndCal", "to": "endcal"},
        {"after": "timeout24Hours", "to": "waitStart"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "endcal", "via": "receivedEndCal", "timeField": "end_cal_time"},
        {"to": "waitStart", "via": "timeout:timeout24Hours"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "timeout24": {
      "entry": [
        {
          "type": "sendSms",
          "template": "We haven't heard from you in a while. Remember to text \"STARTCAL\" when your calories start in the morning and \"ENDCAL\" when your calories finish at night! Let us know if you need help.",
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "participant"}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} Baseline] A participant did not send STARTCAL or ENDCAL yesterday.",
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"},
          "suppressOnRestore": true
        }
      ],
      "transitions": [{"auto": true, "to": "waitStart"}],
      "manualMoves": [
        {"to": "waitStart", "via": "auto"}
      ]
    },
    "endProtocol": {
      "terminal": true,
      "transitions": [],
      "manualMoves": []
    }
  }
}
$ssdef$::jsonb);

INSERT INTO fasting_staging.protocol_defs (file_name, def_name, definition) VALUES
('Control.v1.json', 'Control', $ssdef$
{
  "schemaVersion": 1,
  "name": "Control",
  "initialState": "initial",
  "timers": ["startWarnDeadline", "endWarnDeadline", "timeout24Hours", "timeout8pm"],
  "settings": {
    "endProtocolEvent": "receivedEndProtocol",
    "collection": "none"
  },
  "restore": {
    "policy": "same-day-else-reset",
    "terminalStates": ["endProtocol"],
    "fallbackState": "waitStart",
    "noSaveStateEvent": "receivedWaitStart",
    "dayBoundaryHour": 4,
    "dayBoundaryMinute": 5,
    "replay": {
      "waitStart": {"restoring": ["receivedWaitStart"]},
      "warnStartCal": {"restoring": ["receivedWarnStart"]},
      "startcal": {"restoring": ["receivedStartCal"]},
      "warnEndCal": {"restoring": ["receivedWarnEndCal"]},
      "endcal": {"restoring": ["receivedStartCal", "receivedEndCal"]}
    },
    "resetFire": "receivedWaitStart"
  },
  "eventMappings": {
    "sms": {
      "defaultAction": "ignore",
      "ignoreKeywords": ["snack"],
      "fallbackReply": "Your text was not understood. Please send \"STARTCAL\" when you begin calories for the day; \"ENDCAL\" when you are done with calories for the day.",
      "rules": [
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["waitStart", "warnStartCal"],
          "condition": {"type": "fieldWasYesterday", "field": "start_cal_time", "boundaryHour": 4, "boundaryMinute": 0},
          "notifyAdmins": "[{study} Control] A participant sent ENDCAL after 4am and before STARTCAL.",
          "flagLastMessage": "isAfter8pm"
        },
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["waitStart", "warnStartCal"],
          "reply": "No STARTCAL received yet today. Please send us your \"STARTCAL\" so we know when your calories began today."
        },
        {
          "keywords": ["startcal"],
          "inStates": ["waitStart", "warnStartCal", "startcal"],
          "parseTime": true,
          "saveAs": "start_cal_time",
          "maxBareLength": 8,
          "fire": "receivedStartCal",
          "parseErrorReply": "Your STARTCAL time was not understood. Please send \"STARTCAL\" again with your starting time. For example, \"STARTCAL 7:30 am\"."
        },
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["startcal", "warnEndCal", "endcal"],
          "parseTime": true,
          "saveAs": "end_cal_time",
          "maxBareLength": 7,
          "fire": "receivedEndCal",
          "parseErrorReply": "Your ENDCAL time was not understood. Please send \"ENDCAL\" again with your ending time. For example, \"ENDCAL 7:30 pm\"."
        },
        {
          "keywords": ["startcal"],
          "inStates": ["warnEndCal", "endcal"],
          "reply": "Your text was not understood. Text {admin_phone} if you need help."
        }
      ]
    }
  },
  "states": {
    "initial": {
      "transitions": [
        {"event": "receivedWaitStart", "to": "waitStart"},
        {"event": "receivedWarnStart", "to": "warnStartCal"},
        {"event": "receivedStartCal", "to": "startcal"},
        {"event": "receivedWarnEndCal", "to": "warnEndCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "waitStart", "via": "receivedWaitStart"},
        {"to": "warnStartCal", "via": "receivedWarnStart"},
        {"to": "startcal", "via": "receivedStartCal"},
        {"to": "warnEndCal", "via": "receivedWarnEndCal"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "waitStart": {
      "entry": [
        {"type": "clearVars", "keys": ["sentMissedEndCal"]},
        {
          "type": "setTimer",
          "timer": "startWarnDeadline",
          "deadline": {
            "type": "secondsToClock",
            "hour": 11,
            "minute": 59,
            "second": 30,
            "ifPastAddDay": false,
            "addDayIf": {"type": "afterClock", "hour": 16, "minute": 45},
            "minSeconds": 300
          }
        }
      ],
      "transitions": [
        {"event": "receivedStartCal", "to": "startcal"},
        {"after": "startWarnDeadline", "to": "warnStartCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "warnStartCal", "via": "timeout:startWarnDeadline"},
        {"to": "startcal", "via": "receivedStartCal", "timeField": "start_cal_time"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "warnStartCal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "timeout8pm",
          "deadline": {"type": "secondsToClock", "hour": 20, "minute": 0, "ifPastAddDay": true}
        },
        {
          "type": "setTimer",
          "timer": "timeout24Hours",
          "deadline": {"type": "secondsToClock", "hour": 3, "minute": 59, "second": 30, "ifPastAddDay": true}
        },
        {"type": "sendSms", "template": "Remember to text \"STARTCAL\" when your calories start for the day and \"ENDCAL\" when your calories finish at night. Thank you!"}
      ],
      "transitions": [
        {"event": "receivedStartCal", "to": "startcal"},
        {"after": "timeout8pm", "to": "warnStartCal"},
        {"after": "timeout24Hours", "to": "timeout24"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "timeout24", "via": "timeout:timeout24Hours"},
        {"to": "warnStartCal", "via": "timeout:timeout8pm"},
        {"to": "startcal", "via": "receivedStartCal", "timeField": "start_cal_time"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "startcal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "endWarnDeadline",
          "deadline": {"type": "secondsToClock", "hour": 20, "minute": 59, "second": 30, "ifPastAddDay": false, "minSeconds": 300}
        }
      ],
      "transitions": [
        {"event": "receivedStartCal", "to": "startcal"},
        {"event": "receivedEndCal", "to": "endcal"},
        {"after": "endWarnDeadline", "to": "warnEndCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "startcal", "via": "receivedStartCal", "timeField": "start_cal_time"},
        {"to": "endcal", "via": "receivedEndCal", "timeField": "end_cal_time"},
        {"to": "warnEndCal", "via": "timeout:endWarnDeadline"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "warnEndCal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "timeout24Hours",
          "deadline": {"type": "secondsToClock", "hour": 3, "minute": 59, "second": 30, "ifPastAddDay": true}
        },
        {"type": "sendSms", "template": "Remember to enter your \"ENDCAL\" tonight after your last calories. Thank you!"}
      ],
      "transitions": [
        {"event": "receivedEndCal", "to": "endcal"},
        {"after": "timeout24Hours", "to": "missedEndCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "missedEndCal", "via": "timeout:timeout24Hours"},
        {"to": "endcal", "via": "receivedEndCal"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "missedEndCal": {
      "entry": [
        {
          "type": "notifyAdmins",
          "template": "[{study} Control] A participant missed their ENDCAL.",
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"},
          "suppressOnRestore": true
        },
        {"type": "setVar", "key": "sentMissedEndCal", "value": "true"},
        {
          "type": "sendSms",
          "template": "We haven't heard from you in a while. Remember to text \"STARTCAL\" when your calories start in the morning and \"ENDCAL\" when your calories finish at night! Let us know if you need help.",
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "participant"}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} Control] A participant did not send STARTCAL or ENDCAL yesterday.",
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"},
          "suppressOnRestore": true,
          "suppressIf": {"type": "varEquals", "key": "sentMissedEndCal", "value": "true"}
        }
      ],
      "transitions": [{"auto": true, "to": "waitStart"}],
      "manualMoves": []
    },
    "endcal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "timeout24Hours",
          "deadline": {"type": "secondsToClock", "hour": 3, "minute": 59, "second": 30, "ifPastAddDay": true}
        }
      ],
      "transitions": [
        {"event": "receivedEndCal", "to": "endcal"},
        {"after": "timeout24Hours", "to": "waitStart"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "endcal", "via": "receivedEndCal", "timeField": "end_cal_time"},
        {"to": "waitStart", "via": "timeout:timeout24Hours"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "timeout24": {
      "entry": [
        {
          "type": "sendSms",
          "template": "We haven't heard from you in a while. Remember to text \"STARTCAL\" when your calories start in the morning and \"ENDCAL\" when your calories finish at night! Let us know if you need help.",
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "participant"}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} Control] A participant did not send STARTCAL or ENDCAL yesterday.",
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"},
          "suppressOnRestore": true,
          "suppressIf": {"type": "varEquals", "key": "sentMissedEndCal", "value": "true"}
        }
      ],
      "transitions": [{"auto": true, "to": "waitStart"}],
      "manualMoves": [
        {"to": "waitStart", "via": "auto"}
      ]
    },
    "endProtocol": {
      "terminal": true,
      "transitions": [],
      "manualMoves": []
    }
  }
}
$ssdef$::jsonb);

INSERT INTO fasting_staging.protocol_defs (file_name, def_name, definition) VALUES
('DailyMessage.v1.json', 'DailyMessage', $ssdef$
{
  "schemaVersion": 1,
  "name": "DailyMessage",
  "initialState": "initial",
  "timers": ["timeout24Hours"],
  "settings": {
    "endProtocolEvent": "receivedEndProtocol",
    "collection": "none"
  },
  "restore": {
    "policy": "recompute-from-clock",
    "terminalStates": ["endProtocol"],
    "noSaveStateEvent": "receivedWaitDay",
    "recomputeRules": [
      {"if": {"type": "always"}, "fire": "receivedWaitDay"}
    ],
    "resetFire": "recomputeRules"
  },
  "eventMappings": {
    "sms": {"defaultAction": "ignore"}
  },
  "states": {
    "initial": {
      "transitions": [
        {"event": "receivedWaitDay", "to": "waitDay"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": []
    },
    "waitDay": {
      "entry": [
        {"type": "setTimer", "timer": "timeout24Hours", "deadline": {"type": "secondsToClock", "hour": 20, "minute": 0, "ifPastAddDay": true}}
      ],
      "transitions": [
        {"after": "timeout24Hours", "to": "sendDailyMessage"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": []
    },
    "sendDailyMessage": {
      "entry": [
        {
          "type": "sendSms",
          "templates": [
            "Remember the golden rule: Once you end calories today, you need to be calorie free until tomorrow!",
            "Develop a plan to help you stay calorie free overnight.",
            "Hide snacks to curb late night nibbles.",
            "If you struggle sticking with it, distract yourself with a hobby or a good book!",
            "Drink fizzy water to help with the cravings.",
            "Try drinking herbal tea for a tasty way to be calorie free.",
            "New behaviors take time to adjust to, you've got this.",
            "Enjoy black coffee or tea with no sugar and stay calorie free. Phew!",
            "Top tip: drink fizzy water from a wine glass if you are craving a glass of vino.",
            "Your own mind is your greatest motivator. You CAN do this!",
            "It's not just on game shows where it helps to phone a friend. Chatting with friends helps distract you AND relax at the end of the day.",
            "Plan your meals ahead of time so you're ready when your eating window starts!",
            "Set reminders on your phone to help stay within your eating window.",
            "Keep healthy snacks ready for your eating window to avoid last-minute unhealthy choices.",
            "Distract yourself during cravings - go for a walk, read, or do a quick task!",
            "Tell a friend or family member about your time-restricted eating plan to help with accountability.",
            "Don't panic if you slip up - just return to your schedule the next day!",
            "Celebrate the small wins - every day you stick to your time-restricted eating window is progress!",
            "Remind yourself why you started. Your goals matter—every day you stick to the plan is progress!",
            "Your body loves consistency! Eating within a set window helps align your metabolism with your circadian rhythms.",
            "Time-restricted eating may help improve blood sugar and insulin sensitivity. Your consistency is paying off!"
          ]
        }
      ],
      "transitions": [{"auto": true, "to": "waitDay"}],
      "manualMoves": []
    },
    "endProtocol": {
      "terminal": true,
      "transitions": [],
      "manualMoves": []
    }
  }
}
$ssdef$::jsonb);

INSERT INTO fasting_staging.protocol_defs (file_name, def_name, definition) VALUES
('Sleep.v1.json', 'Sleep', $ssdef$
{
  "schemaVersion": 1,
  "name": "Sleep",
  "initialState": "initial",
  "timers": ["timeout24Hours", "sleepWarnDeadline", "wakeWarnDeadline"],
  "settings": {
    "endProtocolEvent": "receivedEndProtocol",
    "collection": "none"
  },
  "restore": {
    "policy": "same-day-else-reset",
    "terminalStates": ["endProtocol"],
    "fallbackState": "waitSleep",
    "noSaveStateEvent": "receivedWaitSleep",
    "dayBoundaryHour": 17,
    "dayBoundaryMinute": 5,
    "replay": {
      "initial": {"after": ["receivedWaitSleep"]},
      "waitSleep": {"restoring": ["receivedWaitSleep"]},
      "warnSleep": {"restoring": ["receivedWarnSleep"]},
      "sleep": {"restoring": ["receivedSleep"]},
      "warnWake": {"restoring": ["recievedWarnWake"]},
      "wake": {"restoring": ["receivedWake"]},
      "missedWake": {"after": ["receivedWaitSleep"]},
      "timeout24": {"after": ["receivedWaitSleep"]}
    },
    "resetFire": "receivedWaitSleep"
  },
  "eventMappings": {
    "sms": {
      "defaultAction": "ignore",
      "fallbackReply": "Your text was not understood. Please send \"SLEEP\" before you begin trying to fall asleep; \"WAKE\" soon after you wake up in the morning.",
      "rules": [
        {
          "keywords": ["wake"],
          "inStates": ["waitSleep", "warnSleep"],
          "condition": {"type": "fieldWasYesterday", "field": "sleep_time", "boundaryHour": 4, "boundaryMinute": 0},
          "flagLastMessage": "wakeBeforeSleep",
          "notifyAdmins": "[Sleep Baseline ({study})] A participant sent WAKE for yesterday before SLEEP and WAKE for today."
        },
        {
          "keywords": ["wake"],
          "inStates": ["waitSleep", "warnSleep"],
          "reply": "No sleep time received for last night. Please send \"SLEEP\" time first followed by your \"WAKE\" time this morning."
        },
        {
          "keywords": ["sleep"],
          "inStates": ["waitSleep", "warnSleep", "sleep"],
          "parseTime": true,
          "saveAs": "sleep_time",
          "maxBareLength": 5,
          "fire": "receivedSleep",
          "parseErrorReply": "Your SLEEP time was not understood. Please send \"SLEEP\" again with the time you started falling asleep. For example, \"SLEEP 9:30 pm\"."
        },
        {
          "keywords": ["wake"],
          "inStates": ["sleep", "warnWake", "wake"],
          "parseTime": true,
          "saveAs": "wake_time",
          "maxBareLength": 4,
          "fire": "receivedWake",
          "parseErrorReply": "Your WAKE time was not understood. Please send \"WAKE\" again with the time you woke up. For example, \"WAKE 7:30 am\"."
        }
      ]
    }
  },
  "states": {
    "initial": {
      "transitions": [
        {"event": "receivedWaitSleep", "to": "waitSleep"},
        {"event": "receivedWarnSleep", "to": "warnSleep"},
        {"event": "receivedSleep", "to": "sleep"},
        {"event": "receivedWake", "to": "wake"},
        {"event": "recievedWarnWake", "to": "warnWake"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "waitSleep", "via": "receivedWaitSleep"},
        {"to": "warnSleep", "via": "receivedWarnSleep"},
        {"to": "sleep", "via": "receivedSleep"},
        {"to": "warnWake", "via": "recievedWarnWake"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "waitSleep": {
      "entry": [
        {"type": "clearVars", "keys": ["hasBeenWarned"]},
        {
          "type": "setTimer",
          "timer": "sleepWarnDeadline",
          "deadline": {
            "type": "secondsToClock",
            "hour": 11,
            "minute": 59,
            "second": 30,
            "addDayIf": {"type": "afterClock", "hour": 16, "minute": 45},
            "minSeconds": 300
          }
        }
      ],
      "transitions": [
        {"event": "receivedSleep", "to": "sleep"},
        {"after": "sleepWarnDeadline", "to": "warnSleep"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "sleep", "via": "receivedSleep", "timeField": "sleep_time"},
        {"to": "warnSleep", "via": "timeout:sleepWarnDeadline"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "warnSleep": {
      "entry": [
        {"type": "setTimer", "timer": "timeout24Hours", "deadline": {"type": "secondsToClock", "hour": 17, "minute": 0, "ifPastAddDay": true}},
        {"type": "sendSms", "template": "We haven't received your \"SLEEP\" time from last night or your \"WAKE\" time from this morning. Please send these by 5:00pm today. Thank you!"},
        {"type": "setVar", "key": "hasBeenWarned", "value": "true"}
      ],
      "transitions": [
        {"event": "receivedSleep", "to": "sleep"},
        {"after": "timeout24Hours", "to": "timeout24"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "timeout24", "via": "timeout:timeout24Hours"},
        {"to": "sleep", "via": "receivedSleep", "timeField": "sleep_time"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "sleep": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "wakeWarnDeadline",
          "deadline": {
            "type": "secondsToClock",
            "hour": 11,
            "minute": 59,
            "second": 30,
            "addDayIf": {"type": "afterClock", "hour": 16, "minute": 45},
            "minSeconds": 300
          }
        }
      ],
      "transitions": [
        {"event": "receivedSleep", "to": "sleep"},
        {"event": "receivedWake", "to": "wake"},
        {"after": "wakeWarnDeadline", "to": "warnWake"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "sleep", "via": "receivedSleep", "timeField": "sleep_time"},
        {"to": "wake", "via": "receivedWake", "timeField": "wake_time"},
        {"to": "warnWake", "via": "timeout:wakeWarnDeadline"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "warnWake": {
      "entry": [
        {"type": "setTimer", "timer": "timeout24Hours", "deadline": {"type": "secondsToClock", "hour": 17, "minute": 0, "ifPastAddDay": true}},
        {
          "type": "sendSms",
          "template": "We haven't received your \"WAKE\" time from this morning. Please send this by 5:00pm today. Thank you!",
          "suppressIf": {"type": "varEquals", "key": "hasBeenWarned", "value": "true"}
        }
      ],
      "transitions": [
        {"event": "receivedWake", "to": "wake"},
        {"after": "timeout24Hours", "to": "missedWake"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "missedWake", "via": "timeout:timeout24Hours"},
        {"to": "wake", "via": "receivedWake", "timeField": "wake_time"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "wake": {
      "entry": [
        {"type": "setTimer", "timer": "timeout24Hours", "deadline": {"type": "secondsToClock", "hour": 17, "minute": 0, "ifPastAddDay": true}}
      ],
      "transitions": [
        {"event": "receivedWake", "to": "wake"},
        {"after": "timeout24Hours", "to": "waitSleep"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "wake", "via": "receivedWake", "timeField": "wake_time"},
        {"to": "waitSleep", "via": "timeout:timeout24Hours"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "missedWake": {
      "entry": [
        {"type": "notifyAdmins", "template": "[Sleep Baseline ({study})] A participant sent their SLEEP time but missed their WAKE time.", "suppressOnRestore": true}
      ],
      "transitions": [{"auto": true, "to": "waitSleep"}],
      "manualMoves": [{"to": "waitSleep", "via": "auto"}]
    },
    "timeout24": {
      "entry": [
        {"type": "notifyAdmins", "template": "[Sleep Baseline ({study})] A participant did not send SLEEP or WAKE during the last cycle.", "suppressOnRestore": false}
      ],
      "transitions": [{"auto": true, "to": "waitSleep"}],
      "manualMoves": [{"to": "waitSleep", "via": "auto"}]
    },
    "endProtocol": {
      "terminal": true,
      "transitions": [],
      "manualMoves": []
    }
  }
}
$ssdef$::jsonb);

INSERT INTO fasting_staging.protocol_defs (file_name, def_name, definition) VALUES
('SleepSurvey.v1.json', 'SleepSurvey', $ssdef$
{
  "schemaVersion": 1,
  "name": "SleepSurvey",
  "initialState": "initial",
  "timers": ["deadlineNoon", "deadline8pm"],
  "settings": {
    "endProtocolEvent": "receivedEndProtocol",
    "collection": "link"
  },
  "restore": {
    "policy": "recompute-from-clock",
    "terminalStates": ["endSurveyProtocol"],
    "recomputeRules": [
      {"if": {"type": "beforeClock", "hour": 12, "minute": 0}, "fire": "isBeforeNoon"},
      {"if": {"type": "beforeClock", "hour": 20, "minute": 0}, "fire": "isBefore8pm"},
      {"if": {"type": "always"}, "fire": "isBeforeNoon"}
    ],
    "resetFire": "recomputeRules"
  },
  "eventMappings": {
    "sms": {"defaultAction": "ignore"}
  },
  "states": {
    "initial": {
      "transitions": [
        {"event": "isBeforeNoon", "to": "waitForNoon"},
        {"event": "isBefore8pm", "to": "waitFor8pm"},
        {"event": "receivedEndProtocol", "to": "endSurveyProtocol"}
      ],
      "manualMoves": [
        {"to": "waitFor8pm", "via": "isBefore8pm"},
        {"to": "waitForNoon", "via": "isBeforeNoon"},
        {"to": "endSurveyProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "waitForNoon": {
      "entry": [
        {"type": "setTimer", "timer": "deadlineNoon", "deadline": {"type": "secondsToClock", "hour": 12, "minute": 0, "ifPastAddDay": true}}
      ],
      "transitions": [
        {"after": "deadlineNoon", "to": "noonSurvey"},
        {"event": "receivedEndProtocol", "to": "endSurveyProtocol"}
      ],
      "manualMoves": [
        {"to": "noonSurvey", "via": "timeout:deadlineNoon"},
        {"to": "endSurveyProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "noonSurvey": {
      "entry": [
        {"type": "generateSurveyToken", "surveyKey": "noonSurvey"},
        {"type": "sendSurveyLink", "template": "Hello! Please take a moment to complete the following survey: {survey_link}"}
      ],
      "transitions": [{"auto": true, "to": "waitFor8pm"}],
      "manualMoves": []
    },
    "waitFor8pm": {
      "entry": [
        {"type": "setTimer", "timer": "deadline8pm", "deadline": {"type": "secondsToClock", "hour": 20, "minute": 0, "ifPastAddDay": true}}
      ],
      "transitions": [
        {"after": "deadline8pm", "to": "survey8pm"},
        {"event": "receivedEndProtocol", "to": "endSurveyProtocol"}
      ],
      "manualMoves": [
        {"to": "survey8pm", "via": "timeout:deadline8pm"},
        {"to": "endSurveyProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "survey8pm": {
      "entry": [
        {"type": "generateSurveyToken", "surveyKey": "eightPmSurvey"},
        {"type": "sendSurveyLink", "template": "Good Evening! Please take a moment to complete the following survey: {survey_link}"}
      ],
      "transitions": [{"auto": true, "to": "waitForNoon"}],
      "manualMoves": []
    },
    "endSurveyProtocol": {
      "terminal": true,
      "transitions": [],
      "manualMoves": []
    }
  },
  "surveys": [
    {
      "key": "noonSurvey",
      "title": "Survey",
      "questions": [
        {"id": "notesLastNight", "type": "textarea", "label": "Do you have any other notes from last night? You can also tell us about anything else you may not have already told us about yesterday?", "required": false},
        {"id": "work-free", "type": "radio", "label": "Is today a work or free day?", "options": ["work", "free"], "required": false}
      ]
    },
    {
      "key": "eightPmSurvey",
      "title": "Survey",
      "questions": [
        {"id": "notesLastNight", "type": "textarea", "label": "Do you have any notes from today? Anything unusual from today?", "required": false},
        {"id": "activityMonitorOff", "type": "radio", "label": "Did you take off your activity monitor today?", "options": ["Yes", "No"], "required": false},
        {"id": "activityMonitorTime", "type": "textarea", "label": "Please tell us when you took off your activity monitor today if you haven’t told us already.", "required": false},
        {"id": "activityMonitorOffDurationHours", "type": "number", "label": "How long did you have your activity monitor off? (Hours)", "required": false},
        {"id": "activityMonitorOffDurationMins", "type": "number", "label": "How long did you have your activity monitor off? (Minutes)", "required": false},
        {"id": "nap", "type": "radio", "label": "Did you take a nap today?", "options": ["Yes", "No"], "required": false},
        {"id": "napTime", "type": "textarea", "label": "If you took a nap today and have not yet recorded it, please let us know when you napped.", "required": false},
        {"id": "napDurationHours", "type": "number", "label": "How long did you take a nap? (Hours)", "required": false},
        {"id": "napDurationMins", "type": "number", "label": "How long did you take a nap? (Minutes)", "required": false}
      ]
    }
  ]
}
$ssdef$::jsonb);

INSERT INTO fasting_staging.protocol_defs (file_name, def_name, definition) VALUES
('TRE.ccw-hpm.v1.json', 'TRE', $ssdef$
{
  "schemaVersion": 1,
  "name": "TRE",
  "initialState": "initial",
  "timers": ["startDeadline", "startWarnDeadline", "endDeadline", "endWarnDeadline", "endOfEpisodeDeadline"],
  "settings": {
    "endProtocolEvent": "receivedEndProtocol",
    "collection": "none"
  },
  "restore": {
    "policy": "same-day-else-reset",
    "terminalStates": ["endProtocol"],
    "fallbackState": "waitStart",
    "noSaveStateEvent": "receivedWaitStart",
    "dayBoundaryHour": 4,
    "dayBoundaryMinute": 5,
    "replay": {
      "waitStart": {"restoring": ["receivedWaitStart"]},
      "warnStartCal": {"restoring": ["receivedWarnStartCal"]},
      "startcal": {"restoring": ["receivedStartCal"]},
      "warnEndCal": {"restoring": ["recievedWarnEndCal"]},
      "endOfEpisode": {"restoring": ["receivedStartCal", "receivedEndCal"]}
    },
    "resetFire": "receivedWaitStart"
  },
  "eventMappings": {
    "sms": {
      "defaultAction": "ignore",
      "ignoreKeywords": ["snack"],
      "fallbackReply": "Your text was not understood. Please send \"STARTCAL\" when you begin calories for the day; \"ENDCAL\" when you are done with calories for the day.",
      "rules": [
        {
          "keywords": ["dayoff", "day off"],
          "inStates": ["waitStart", "warnStartCal", "startcal", "warnEndCal"],
          "fire": "receivedDayOff"
        },
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["waitStart", "warnStartCal"],
          "condition": {"type": "fieldWasYesterday", "field": "start_cal_time", "boundaryHour": 4, "boundaryMinute": 0},
          "flagLastMessage": "fromYesterday",
          "notifyAdmins": "[{study} TRE] A participant sent ENDCAL after 4am and before STARTCAL."
        },
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["waitStart", "warnStartCal"],
          "reply": "No \"STARTCAL\" received yet today. Please send us your \"STARTCAL\" so we know when your calories began today."
        },
        {
          "keywords": ["startcal"],
          "inStates": ["waitStart", "warnStartCal"],
          "parseTime": true,
          "saveAs": "start_cal_time",
          "maxBareLength": 8,
          "fire": "receivedStartCal",
          "parseErrorReply": "Your STARTCAL time was not understood. Please send \"STARTCAL\" again with your starting time. For example, \"STARTCAL 7:30 am\"."
        },
        {
          "keywords": ["startcal"],
          "inStates": ["startcal"],
          "reply": "You've already started consuming calories for the day. Text \"ENDCAL\" when you finish your TRE today."
        },
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["startcal", "warnEndCal"],
          "parseTime": true,
          "saveAs": "end_cal_time",
          "maxBareLength": 7,
          "guard": {"type": "fieldBetweenClock", "field": "end_cal_time", "fromHour": 3, "toHour": 15},
          "guardReply": "We don't recommend that you end calories this early in the day. Try again in the evening. Text {admin_phone} if you want to receive a call about how to manage TRE safely.",
          "fire": "receivedEndCal",
          "parseErrorReply": "Your ENDCAL time was not understood. Please send \"ENDCAL\" again with your ending time. For example, \"ENDCAL 7:30 pm\"."
        },
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["endOfEpisode"],
          "parseTime": true,
          "saveAs": "end_cal_time",
          "maxBareLength": 160,
          "fire": "receivedEndCal"
        },
        {
          "keywords": ["dayoff", "day off"],
          "inStates": ["endOfEpisode"],
          "fire": "receivedDayOff"
        }
      ]
    }
  },
  "states": {
    "initial": {
      "transitions": [
        {"event": "receivedWaitStart", "to": "waitStart"},
        {"event": "receivedWarnStartCal", "to": "warnStartCal"},
        {"event": "receivedStartCal", "to": "startcal"},
        {"event": "recievedWarnEndCal", "to": "warnEndCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "waitStart", "via": "receivedWaitStart"},
        {"to": "warnStartCal", "via": "receivedWarnStartCal"},
        {"to": "startcal", "via": "receivedStartCal"},
        {"to": "warnEndCal", "via": "recievedWarnEndCal"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "waitStart": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "startWarnDeadline",
          "deadline": {
            "type": "secondsToClock",
            "hour": 11,
            "minute": 59,
            "second": 30,
            "ifPastAddDay": false,
            "addDayIf": {"type": "afterClock", "hour": 16, "minute": 45},
            "minSeconds": 300
          }
        }
      ],
      "transitions": [
        {"event": "receivedStartCal", "to": "startcal"},
        {"event": "receivedDayOff", "to": "dayOffWait"},
        {"after": "startWarnDeadline", "to": "warnStartCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "warnStartCal", "via": "timeout:startWarnDeadline"},
        {"to": "startcal", "via": "receivedStartCal", "timeField": "start_cal_time"},
        {"to": "dayOffWait", "via": "receivedDayOff"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "dayOffWait": {
      "entry": [
        {"type": "setVar", "key": "isDayOff", "value": "true"},
        {"type": "setVar", "key": "dayOffNoEndcal", "value": "true"},
        {"type": "sendSms", "template": "Got it, no TRE today! Thank you for telling us. Please still let us know your \"STARTCAL\" and \"ENDCAL\" today."}
      ],
      "transitions": [{"auto": true, "to": "waitStart"}],
      "manualMoves": []
    },
    "warnStartCal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "startDeadline",
          "deadline": {"type": "secondsToClock", "hour": 3, "minute": 59, "second": 30, "ifPastAddDay": true}
        },
        {"type": "sendSms", "template": "No \"STARTCAL\" received yet today. Please send us your \"STARTCAL\" so we know when your calories began today."}
      ],
      "transitions": [
        {"event": "receivedStartCal", "to": "startcal"},
        {"event": "receivedDayOff", "to": "dayOffWarn"},
        {"after": "startDeadline", "to": "missedStartCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "startcal", "via": "receivedStartCal", "timeField": "start_cal_time"},
        {"to": "missedStartCal", "via": "timeout:startDeadline"},
        {"to": "dayOffWarn", "via": "receivedDayOff"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "dayOffWarn": {
      "entry": [
        {"type": "setVar", "key": "isDayOff", "value": "true"},
        {"type": "setVar", "key": "dayOffNoEndcal", "value": "true"},
        {"type": "sendSms", "template": "Got it, no TRE today! Thank you for telling us. Please still let us know your \"STARTCAL\" and \"ENDCAL\" today."}
      ],
      "transitions": [{"auto": true, "to": "warnStartCal"}],
      "manualMoves": []
    },
    "startcal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "endWarnDeadline",
          "deadline": {"type": "secondsToClock", "hour": 20, "minute": 59, "second": 30, "ifPastAddDay": false, "minSeconds": 300}
        },
        {
          "type": "conditionalSms",
          "if": {"type": "fieldAfterClock", "field": "start_cal_time", "hour": 10, "minute": 1},
          "templates": ["Oops, you started your calories too late this morning. Try starting your calories before 10:00am so that you can stop by 8:00pm. Today, please still try to finish your calories by 8:00pm."],
          "else": ["Thank you for sending your STARTCAL! Be sure to finish your last calorie today by {finish_by}."],
          "suppressIf": {"type": "varEquals", "key": "isDayOff", "value": "true"}
        }
      ],
      "transitions": [
        {"event": "receivedStartCal", "to": "startcal"},
        {"event": "receivedEndCal", "to": "endcal"},
        {"event": "receivedDayOff", "to": "dayOffStartCal"},
        {"after": "endWarnDeadline", "to": "warnEndCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "startcal", "via": "receivedStartCal", "timeField": "start_cal_time"},
        {"to": "endcal", "via": "receivedEndCal", "timeField": "end_cal_time"},
        {"to": "warnEndCal", "via": "timeout:endWarnDeadline"},
        {"to": "dayOffStartCal", "via": "receivedDayOff"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "dayOffStartCal": {
      "entry": [
        {"type": "setVar", "key": "isDayOff", "value": "true"},
        {"type": "setVar", "key": "dayOffNoEndcal", "value": "true"},
        {"type": "sendSms", "template": "Got it, no TRE today! Thank you for telling us. Please still let us know your \"ENDCAL\" today."}
      ],
      "transitions": [{"auto": true, "to": "startcal"}],
      "manualMoves": []
    },
    "missedStartCal": {
      "entry": [
        {"type": "incrementCounter", "key": "daysWithoutEndcal"},
        {
          "type": "conditionalSms",
          "if": {"type": "counterAtLeast", "key": "daysWithoutEndcal", "count": 2},
          "templates": ["We haven't heard from you in a while. Text our study team at {admin_phone} if you're struggling to stick with the time-restricted eating."],
          "else": ["We haven't heard from you in a while. Remember to text \"STARTCAL\" when your calories start in the morning and \"ENDCAL\" when your calories finish at night! Let us know if you need help."],
          "suppressIf": {"type": "varEquals", "key": "isDayOff", "value": "true"},
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "participant"}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} TRE] A participant missed their STARTCAL.",
          "suppressIf": {"type": "not", "inner": {"type": "counterAtLeast", "key": "cycles", "count": 7}},
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} TRE] A participant missed their STARTCAL during the trial period (<7 days).",
          "suppressIf": {"type": "counterAtLeast", "key": "cycles", "count": 7},
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"}
        }
      ],
      "transitions": [{"auto": true, "to": "endOfEpisode"}],
      "manualMoves": [{"to": "endOfEpisode", "via": "auto"}]
    },
    "warnEndCal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "endDeadline",
          "deadline": {"type": "secondsToClock", "hour": 3, "minute": 59, "second": 30, "ifPastAddDay": true}
        },
        {"type": "sendSms", "template": "{first_name}, we haven't heard from you. Remember to text \"ENDCAL\" when you go calorie free."}
      ],
      "transitions": [
        {"event": "receivedEndCal", "to": "endcal"},
        {"event": "receivedDayOff", "to": "dayOffWarnEndCal"},
        {"after": "endDeadline", "to": "missedEndCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "endcal", "via": "receivedEndCal", "timeField": "end_cal_time"},
        {"to": "missedEndCal", "via": "timeout:endDeadline"},
        {"to": "dayOffWarnEndCal", "via": "receivedDayOff"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "dayOffWarnEndCal": {
      "entry": [
        {"type": "setVar", "key": "isDayOff", "value": "true"},
        {"type": "setVar", "key": "dayOffNoEndcal", "value": "true"},
        {"type": "sendSms", "template": "Got it, no TRE today! Thank you for telling us. Please still let us know your \"ENDCAL\" today."}
      ],
      "transitions": [{"auto": true, "to": "warnEndCal"}],
      "manualMoves": []
    },
    "endcal": {
      "entry": [
        {
          "type": "treEvaluateEndcal",
          "startField": "start_cal_time",
          "endField": "end_cal_time",
          "minSeconds": 32400,
          "maxSeconds": 39600,
          "lateHour": 20,
          "lateMinute": 5,
          "trialPeriodCycles": 7,
          "cycleCounter": "cycles",
          "repeatCounter": "endcalRepeats",
          "dayOffVar": "isDayOff",
          "ackMessages": [
            "Thanks! You are now running calorie free!",
            "Thanks for your response. You can do it!",
            "Thanks, {first_name}! Here we go! Make sure your kitchen is in lock down!",
            "Message received. Good luck!",
            "Great. Now lock those kitchen cabinets!",
            "Thanks. You are now nibble free.",
            "Message received. Now stay out of the kitchen. Doctor's orders!",
            "Thanks. Now go bolt the refrigerator door shut.",
            "Got it. Now put your tummy to sleep until the morning!",
            "Muchas gracias. Now go put your feet up. You've earned it!"
          ],
          "successMessages": [
            "Good show! Your overall success rate is now {success_rate}!",
            "Perfect! Your overall success rate is now {success_rate}!",
            "Smashing! Your overall success rate is now {success_rate}!",
            "Bravo, {first_name}! Your overall success rate is now {success_rate}!",
            "Jolly good show! Your overall success rate is now {success_rate}!",
            "Superb! Your overall success rate is now {success_rate}!",
            "Great! Your overall success rate is now {success_rate}!"
          ],
          "shortTrialMessages": [
            "Oops, you ended your time-restricted eating {eating_window} too short. Try planning ahead for how you will end your TRE.",
            "Oops, your period of time-restricted eating ended too early! To avoid this, have small snacks on-hand or meals ready to eat during the full eating window time.",
            "Oops, {first_name}, you ended your time-restricted eating too soon! If you need help, get a family member to help you start your fasting time on time!",
            "Oops, your time-restricted eating period was too short today. Try putting Post-Its on your fridge and cupboards to help you remember your target End Calories time!"
          ],
          "shortMessages": [
            "Oops, your eating window ended {eating_window} too early today. Try planning ahead for how you will end your TRE. Your success rate is now {success_rate}.",
            "Oops, your period of time-restricted eating ended too early! To avoid this, have small snacks on-hand or meals ready to eat during the full eating window time. {first_name}, your success rate is now {success_rate}.",
            "Oops, {first_name}, you ended your time-restricted eating too soon! If you need help, get a family member to help you start your fasting time on time! Your success rate is now {success_rate}.",
            "Oops, your time-restricted eating period was too short today. Try putting Post-Its on your fridge and cupboards to help you remember your target End Calories time! Your success rate is now {success_rate}."
          ],
          "longTrialMessages": [
            "Oops, your TRE lasted too long today. Try planning ahead to stay within your eating window goals.",
            "Oops, you slipped up and ended your calories too late this evening. Let's get back on track tomorrow!",
            "Oops, {first_name}, you exceeded the target time window for calories! If you need help, get a family member to help you end your calories on time!",
            "Oops, you slipped up and consumed calories for too long today. Let's get back on track tomorrow!"
          ],
          "longMessages": [
            "Oops, your TRE lasted too long today. Try planning ahead to stay within your eating window goals. Your success rate is now {success_rate}.",
            "Oops, you slipped up and ended your calories too late this evening. Let's get back on track tomorrow! Your success rate is now {success_rate}.",
            "Oops, {first_name}, you exceeded the target time window for calories! If you need help, get a family member to help you end your calories on time! Your success rate is now {success_rate}.",
            "Oops, you slipped up and consumed calories for too long today. Let's get back on track tomorrow! Your success rate is now {success_rate}."
          ],
          "lateMessages": [
            "Oops, you ended your time-restricted eating too late. Try to end your calories before 8pm.",
            "Oops, you ended your time-restricted eating after 8pm. Try planning ahead for how you will end your daily calories earlier.",
            "Oops, you ended your time-restricted eating after 8pm. Try using a recurring alarm or a family member to help you end calories earlier."
          ],
          "neutralMessages": [
            "Your overall success rate is now {success_rate}."
          ]
        },
        {"type": "clearVars", "keys": ["daysWithoutEndcal", "dayOffNoEndcal"]}
      ],
      "transitions": [{"auto": true, "to": "endOfEpisode"}],
      "manualMoves": [{"to": "endOfEpisode", "via": "auto"}]
    },
    "missedEndCal": {
      "entry": [
        {"type": "incrementCounter", "key": "daysWithoutEndcal"},
        {
          "type": "conditionalSms",
          "if": {"type": "counterAtLeast", "key": "daysWithoutEndcal", "count": 2},
          "templates": ["We haven't heard from you in a while. Text our study team at {admin_phone} if you're struggling to stick with the time-restricted eating."],
          "else": [],
          "suppressIf": {"type": "varEquals", "key": "isDayOff", "value": "true"},
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "participant"}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} TRE] A participant missed their ENDCAL.",
          "suppressIf": {"type": "not", "inner": {"type": "counterAtLeast", "key": "cycles", "count": 7}},
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} TRE] A participant missed their ENDCAL during the trial period (<7 days).",
          "suppressIf": {"type": "counterAtLeast", "key": "cycles", "count": 7},
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"}
        }
      ],
      "transitions": [{"auto": true, "to": "endOfEpisode"}],
      "manualMoves": [{"to": "endOfEpisode", "via": "auto"}]
    },
    "endOfEpisode": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "endOfEpisodeDeadline",
          "deadline": {"type": "secondsToClock", "hour": 4, "minute": 0, "ifPastAddDay": true}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} TRE] A participant missed endcal and is on dayoff. If they send endcal in the next cycle it will count towards their success rate.",
          "suppressIf": {"type": "not", "inner": {"type": "varEquals", "key": "dayOffNoEndcal", "value": "true"}}
        }
      ],
      "transitions": [
        {"after": "endOfEpisodeDeadline", "to": "resetEpisodeVariables"},
        {"event": "receivedDayOff", "to": "dayOffEndOfEpisode"},
        {"event": "receivedEndCal", "to": "endcal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "resetEpisodeVariables", "via": "timeout:endOfEpisodeDeadline"},
        {"to": "dayOffEndOfEpisode", "via": "receivedDayOff"},
        {"to": "endcal", "via": "receivedEndCal", "timeField": "end_cal_time"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "dayOffEndOfEpisode": {
      "entry": [
        {
          "type": "conditionalSms",
          "if": {"type": "varEquals", "key": "isDayOff", "value": "true"},
          "templates": ["You have already sent \"DAYOFF\" for today."],
          "else": ["Got it, no TRE today! Thank you for telling us. Please still let us know your \"ENDCAL\" today."]
        },
        {
          "type": "treDayOffRollback",
          "suppressIf": {"type": "varEquals", "key": "isDayOff", "value": "true"}
        },
        {"type": "setVar", "key": "isDayOff", "value": "true"},
        {"type": "setVar", "key": "dayOffNoEndcal", "value": "true"}
      ],
      "transitions": [{"auto": true, "to": "endOfEpisode"}],
      "manualMoves": []
    },
    "resetEpisodeVariables": {
      "entry": [
        {"type": "incrementCounter", "key": "cycles"},
        {"type": "clearVars", "keys": ["isDayOff", "endcalRepeats", "wasSuccessfulFast", "wasAfter8pm", "dayOffNoEndcal"]}
      ],
      "transitions": [{"auto": true, "to": "waitStart"}],
      "manualMoves": [{"to": "waitStart", "via": "auto"}]
    },
    "endProtocol": {
      "terminal": true,
      "transitions": [],
      "manualMoves": []
    }
  }
}
$ssdef$::jsonb);

INSERT INTO fasting_staging.protocol_defs (file_name, def_name, definition) VALUES
('TRE.timed.v1.json', 'TRE', $ssdef$
{
  "schemaVersion": 1,
  "name": "TRE",
  "initialState": "initial",
  "timers": ["startDeadline", "startWarnDeadline", "endDeadline", "endWarnDeadline", "endOfEpisodeDeadline"],
  "settings": {
    "endProtocolEvent": "receivedEndProtocol",
    "collection": "none"
  },
  "restore": {
    "policy": "same-day-else-reset",
    "terminalStates": ["endProtocol"],
    "fallbackState": "waitStart",
    "noSaveStateEvent": "receivedWaitStart",
    "dayBoundaryHour": 4,
    "dayBoundaryMinute": 5,
    "replay": {
      "waitStart": {"restoring": ["receivedWaitStart"]},
      "warnStartCal": {"restoring": ["receivedWarnStartCal"]},
      "startcal": {"restoring": ["receivedStartCal"]},
      "warnEndCal": {"restoring": ["recievedWarnEndCal"]},
      "endOfEpisode": {"restoring": ["receivedStartCal", "receivedEndCal"]}
    },
    "resetFire": "receivedWaitStart"
  },
  "eventMappings": {
    "sms": {
      "defaultAction": "ignore",
      "ignoreKeywords": ["snack"],
      "fallbackReply": "Your text was not understood. Please send \"STARTCAL\" when you begin calories for the day; \"ENDCAL\" when you are done with calories for the day.",
      "rules": [
        {
          "keywords": ["dayoff", "day off"],
          "inStates": ["waitStart", "warnStartCal", "startcal", "warnEndCal"],
          "fire": "receivedDayOff"
        },
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["waitStart", "warnStartCal"],
          "condition": {"type": "fieldWasYesterday", "field": "start_cal_time", "boundaryHour": 4, "boundaryMinute": 0},
          "flagLastMessage": "fromYesterday",
          "notifyAdmins": "[{study} TRE] A participant sent ENDCAL after 4am and before STARTCAL."
        },
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["waitStart", "warnStartCal"],
          "reply": "No \"STARTCAL\" received yet today. Please send us your \"STARTCAL\" so we know when your calories began today."
        },
        {
          "keywords": ["startcal"],
          "inStates": ["waitStart", "warnStartCal"],
          "parseTime": true,
          "saveAs": "start_cal_time",
          "maxBareLength": 8,
          "fire": "receivedStartCal",
          "parseErrorReply": "Your STARTCAL time was not understood. Please send \"STARTCAL\" again with your starting time. For example, \"STARTCAL 7:30 am\"."
        },
        {
          "keywords": ["startcal"],
          "inStates": ["startcal"],
          "reply": "You've already started consuming calories for the day. Text \"ENDCAL\" when you finish your TRE today."
        },
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["startcal", "warnEndCal"],
          "parseTime": true,
          "saveAs": "end_cal_time",
          "maxBareLength": 7,
          "guard": {"type": "fieldBetweenClock", "field": "end_cal_time", "fromHour": 3, "toHour": 15},
          "guardReply": "We don't recommend that you end calories this early in the day. Try again in the evening. Text {admin_phone} if you want to receive a call about how to manage TRE safely.",
          "fire": "receivedEndCal",
          "parseErrorReply": "Your ENDCAL time was not understood. Please send \"ENDCAL\" again with your ending time. For example, \"ENDCAL 7:30 pm\"."
        },
        {
          "keywords": ["endcal", "stopcal"],
          "inStates": ["endOfEpisode"],
          "parseTime": true,
          "saveAs": "end_cal_time",
          "maxBareLength": 160,
          "fire": "receivedEndCal"
        },
        {
          "keywords": ["dayoff", "day off"],
          "inStates": ["endOfEpisode"],
          "fire": "receivedDayOff"
        }
      ]
    }
  },
  "states": {
    "initial": {
      "transitions": [
        {"event": "receivedWaitStart", "to": "waitStart"},
        {"event": "receivedWarnStartCal", "to": "warnStartCal"},
        {"event": "receivedStartCal", "to": "startcal"},
        {"event": "recievedWarnEndCal", "to": "warnEndCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "waitStart", "via": "receivedWaitStart"},
        {"to": "warnStartCal", "via": "receivedWarnStartCal"},
        {"to": "startcal", "via": "receivedStartCal"},
        {"to": "warnEndCal", "via": "recievedWarnEndCal"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "waitStart": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "startWarnDeadline",
          "deadline": {
            "type": "secondsToClock",
            "hour": 11,
            "minute": 59,
            "second": 30,
            "ifPastAddDay": false,
            "addDayIf": {"type": "afterClock", "hour": 16, "minute": 45},
            "minSeconds": 300
          }
        }
      ],
      "transitions": [
        {"event": "receivedStartCal", "to": "startcal"},
        {"event": "receivedDayOff", "to": "dayOffWait"},
        {"after": "startWarnDeadline", "to": "warnStartCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "warnStartCal", "via": "timeout:startWarnDeadline"},
        {"to": "startcal", "via": "receivedStartCal", "timeField": "start_cal_time"},
        {"to": "dayOffWait", "via": "receivedDayOff"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "dayOffWait": {
      "entry": [
        {"type": "setVar", "key": "isDayOff", "value": "true"},
        {"type": "setVar", "key": "dayOffNoEndcal", "value": "true"},
        {"type": "sendSms", "template": "Got it, no TRE today! Thank you for telling us. Please still let us know your \"STARTCAL\" and \"ENDCAL\" today."}
      ],
      "transitions": [{"auto": true, "to": "waitStart"}],
      "manualMoves": []
    },
    "warnStartCal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "startDeadline",
          "deadline": {"type": "secondsToClock", "hour": 3, "minute": 59, "second": 30, "ifPastAddDay": true}
        },
        {"type": "sendSms", "template": "No \"STARTCAL\" received yet today. Please send us your \"STARTCAL\" so we know when your calories began today."}
      ],
      "transitions": [
        {"event": "receivedStartCal", "to": "startcal"},
        {"event": "receivedDayOff", "to": "dayOffWarn"},
        {"after": "startDeadline", "to": "missedStartCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "startcal", "via": "receivedStartCal", "timeField": "start_cal_time"},
        {"to": "missedStartCal", "via": "timeout:startDeadline"},
        {"to": "dayOffWarn", "via": "receivedDayOff"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "dayOffWarn": {
      "entry": [
        {"type": "setVar", "key": "isDayOff", "value": "true"},
        {"type": "setVar", "key": "dayOffNoEndcal", "value": "true"},
        {"type": "sendSms", "template": "Got it, no TRE today! Thank you for telling us. Please still let us know your \"STARTCAL\" and \"ENDCAL\" today."}
      ],
      "transitions": [{"auto": true, "to": "warnStartCal"}],
      "manualMoves": []
    },
    "startcal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "endWarnDeadline",
          "deadline": {"type": "secondsToClock", "hour": 20, "minute": 59, "second": 30, "ifPastAddDay": false, "minSeconds": 300}
        },
        {
          "type": "conditionalSms",
          "if": {"type": "fieldAfterClock", "field": "start_cal_time", "hour": 10, "minute": 1},
          "templates": ["Oops, you started your calories too late this morning. Try starting your calories before 10:00am so that you can stop by 8:00pm. Today, please still try to finish your calories by 8:00pm."],
          "else": ["Thank you for sending your STARTCAL! Be sure to finish your last calorie today by {finish_by}."],
          "suppressIf": {"type": "varEquals", "key": "isDayOff", "value": "true"}
        }
      ],
      "transitions": [
        {"event": "receivedStartCal", "to": "startcal"},
        {"event": "receivedEndCal", "to": "endcal"},
        {"event": "receivedDayOff", "to": "dayOffStartCal"},
        {"after": "endWarnDeadline", "to": "warnEndCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "startcal", "via": "receivedStartCal", "timeField": "start_cal_time"},
        {"to": "endcal", "via": "receivedEndCal", "timeField": "end_cal_time"},
        {"to": "warnEndCal", "via": "timeout:endWarnDeadline"},
        {"to": "dayOffStartCal", "via": "receivedDayOff"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "dayOffStartCal": {
      "entry": [
        {"type": "setVar", "key": "isDayOff", "value": "true"},
        {"type": "setVar", "key": "dayOffNoEndcal", "value": "true"},
        {"type": "sendSms", "template": "Got it, no TRE today! Thank you for telling us. Please still let us know your \"ENDCAL\" today."}
      ],
      "transitions": [{"auto": true, "to": "startcal"}],
      "manualMoves": []
    },
    "missedStartCal": {
      "entry": [
        {"type": "incrementCounter", "key": "daysWithoutEndcal"},
        {
          "type": "conditionalSms",
          "if": {"type": "counterAtLeast", "key": "daysWithoutEndcal", "count": 2},
          "templates": ["We haven't heard from you in a while. Text our study team at {admin_phone} if you're struggling to stick with the time-restricted eating."],
          "else": ["We haven't heard from you in a while. Remember to text \"STARTCAL\" when your calories start in the morning and \"ENDCAL\" when your calories finish at night! Let us know if you need help."],
          "suppressIf": {"type": "varEquals", "key": "isDayOff", "value": "true"},
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "participant"}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} TRE] A participant missed their STARTCAL.",
          "suppressIf": {"type": "not", "inner": {"type": "counterAtLeast", "key": "cycles", "count": 7}},
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} TRE] A participant missed their STARTCAL during the trial period (<7 days).",
          "suppressIf": {"type": "counterAtLeast", "key": "cycles", "count": 7},
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"}
        }
      ],
      "transitions": [{"auto": true, "to": "endOfEpisode"}],
      "manualMoves": [{"to": "endOfEpisode", "via": "auto"}]
    },
    "warnEndCal": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "endDeadline",
          "deadline": {"type": "secondsToClock", "hour": 3, "minute": 59, "second": 30, "ifPastAddDay": true}
        },
        {"type": "sendSms", "template": "{first_name}, we haven't heard from you. Remember to text \"ENDCAL\" when you go calorie free."}
      ],
      "transitions": [
        {"event": "receivedEndCal", "to": "endcal"},
        {"event": "receivedDayOff", "to": "dayOffWarnEndCal"},
        {"after": "endDeadline", "to": "missedEndCal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "endcal", "via": "receivedEndCal", "timeField": "end_cal_time"},
        {"to": "missedEndCal", "via": "timeout:endDeadline"},
        {"to": "dayOffWarnEndCal", "via": "receivedDayOff"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "dayOffWarnEndCal": {
      "entry": [
        {"type": "setVar", "key": "isDayOff", "value": "true"},
        {"type": "setVar", "key": "dayOffNoEndcal", "value": "true"},
        {"type": "sendSms", "template": "Got it, no TRE today! Thank you for telling us. Please still let us know your \"ENDCAL\" today."}
      ],
      "transitions": [{"auto": true, "to": "warnEndCal"}],
      "manualMoves": []
    },
    "endcal": {
      "entry": [
        {
          "type": "treEvaluateEndcal",
          "startField": "start_cal_time",
          "endField": "end_cal_time",
          "minSeconds": 32400,
          "maxSeconds": 39600,
          "lateHour": 20,
          "lateMinute": 5,
          "trialPeriodCycles": 7,
          "cycleCounter": "cycles",
          "repeatCounter": "endcalRepeats",
          "dayOffVar": "isDayOff",
          "ackMessages": [
            "Thanks! You are now running calorie free!",
            "Thanks for your response. You can do it!",
            "Thanks, {first_name}! Here we go! Make sure your kitchen is in lock down!",
            "Message received. Good luck!",
            "Great. Now lock those kitchen cabinets!",
            "Thanks. You are now nibble free.",
            "Message received. Now stay out of the kitchen. Doctor's orders!",
            "Thanks. Now go bolt the refrigerator door shut.",
            "Got it. Now put your tummy to sleep until the morning!",
            "Muchas gracias. Now go put your feet up. You've earned it!",
            "Thank you for entering the time you stopped your calories. Have some unsweetened herbal tea and relax!",
            "Fantastic! Way to curb the calories at night!",
            "Thanks for entering in your endcal time!",
            "No more calories til tomorrow! Great work!",
            "We appreciate you sending in your endcal times!",
            "Awesome! Another day done—time to relax and recharge.",
            "Well done! Your commitment is inspiring—cheering you on all the way!",
            "Great job today! You’re building healthy habits one day at a time. Keep it up!"
          ],
          "successMessages": [
            "Good show! Your overall success rate is now {success_rate}!",
            "Perfect! Your overall success rate is now {success_rate}!",
            "Smashing! Your overall success rate is now {success_rate}!",
            "Bravo, {first_name}! Your overall success rate is now {success_rate}!",
            "Jolly good show! Your overall success rate is now {success_rate}!",
            "Superb! Your overall success rate is now {success_rate}!",
            "Great! Your overall success rate is now {success_rate}!",
            "You crushed it, {first_name}! Your overall success rate is now {success_rate}!",
            "Nice work! Your overall success rate is now {success_rate}!"
          ],
          "shortTrialMessages": [
            "Oops, you ended your time-restricted eating {eating_window} too short. Try planning ahead for how you will end your TRE.",
            "Oops, your period of time-restricted eating ended too early! To avoid this, have small snacks on-hand or meals ready to eat during the full eating window time.",
            "Oops, {first_name}, you ended your time-restricted eating too soon! If you need help, get a family member to help you start your fasting time on time!",
            "Oops, your time-restricted eating period was too short today. Try putting Post-Its on your fridge and cupboards to help you remember your target End Calories time!"
          ],
          "shortMessages": [
            "Oops, your eating window ended {eating_window} too early today. Try planning ahead for how you will end your TRE. Your success rate is now {success_rate}.",
            "Oops, your period of time-restricted eating ended too early! To avoid this, have small snacks on-hand or meals ready to eat during the full eating window time. {first_name}, your success rate is now {success_rate}.",
            "Oops, {first_name}, you ended your time-restricted eating too soon! If you need help, get a family member to help you start your fasting time on time! Your success rate is now {success_rate}.",
            "Oops, your time-restricted eating period was too short today. Try putting Post-Its on your fridge and cupboards to help you remember your target End Calories time! Your success rate is now {success_rate}."
          ],
          "longTrialMessages": [
            "Oops, your TRE lasted too long today. Try planning ahead to stay within your eating window goals.",
            "Oops, you slipped up and ended your calories too late this evening. Let's get back on track tomorrow!",
            "Oops, {first_name}, you exceeded the target time window for calories! If you need help, get a family member to help you end your calories on time!",
            "Oops, you slipped up and consumed calories for too long today. Let's get back on track tomorrow!"
          ],
          "longMessages": [
            "Oops, your TRE lasted too long today. Try planning ahead to stay within your eating window goals. Your success rate is now {success_rate}.",
            "Oops, you slipped up and ended your calories too late this evening. Let's get back on track tomorrow! Your success rate is now {success_rate}.",
            "Oops, {first_name}, you exceeded the target time window for calories! If you need help, get a family member to help you end your calories on time! Your success rate is now {success_rate}.",
            "Oops, you slipped up and consumed calories for too long today. Let's get back on track tomorrow! Your success rate is now {success_rate}."
          ],
          "lateMessages": [
            "Oops, you ended your time-restricted eating too late. Try to end your calories before 8pm.",
            "Oops, you ended your time-restricted eating after 8pm. Try planning ahead for how you will end your daily calories earlier.",
            "Oops, you ended your time-restricted eating after 8pm. Try using a recurring alarm or a family member to help you end calories earlier."
          ],
          "neutralMessages": [
            "Your overall success rate is now {success_rate}."
          ]
        },
        {"type": "clearVars", "keys": ["daysWithoutEndcal", "dayOffNoEndcal"]}
      ],
      "transitions": [{"auto": true, "to": "endOfEpisode"}],
      "manualMoves": [{"to": "endOfEpisode", "via": "auto"}]
    },
    "missedEndCal": {
      "entry": [
        {"type": "incrementCounter", "key": "daysWithoutEndcal"},
        {
          "type": "conditionalSms",
          "if": {"type": "counterAtLeast", "key": "daysWithoutEndcal", "count": 2},
          "templates": ["We haven't heard from you in a while. Text our study team at {admin_phone} if you're struggling to stick with the time-restricted eating."],
          "else": [],
          "suppressIf": {"type": "varEquals", "key": "isDayOff", "value": "true"},
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "participant"}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} TRE] A participant missed their ENDCAL.",
          "suppressIf": {"type": "not", "inner": {"type": "counterAtLeast", "key": "cycles", "count": 7}},
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} TRE] A participant missed their ENDCAL during the trial period (<7 days).",
          "suppressIf": {"type": "counterAtLeast", "key": "cycles", "count": 7},
          "deliverAt": {"hour": 8, "minute": 0, "timezone": "admin"}
        }
      ],
      "transitions": [{"auto": true, "to": "endOfEpisode"}],
      "manualMoves": [{"to": "endOfEpisode", "via": "auto"}]
    },
    "endOfEpisode": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "endOfEpisodeDeadline",
          "deadline": {"type": "secondsToClock", "hour": 4, "minute": 0, "ifPastAddDay": true}
        },
        {
          "type": "notifyAdmins",
          "template": "[{study} TRE] A participant missed endcal and is on dayoff. If they send endcal in the next cycle it will count towards their success rate.",
          "suppressIf": {"type": "not", "inner": {"type": "varEquals", "key": "dayOffNoEndcal", "value": "true"}}
        }
      ],
      "transitions": [
        {"after": "endOfEpisodeDeadline", "to": "resetEpisodeVariables"},
        {"event": "receivedDayOff", "to": "dayOffEndOfEpisode"},
        {"event": "receivedEndCal", "to": "endcal"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": [
        {"to": "resetEpisodeVariables", "via": "timeout:endOfEpisodeDeadline"},
        {"to": "dayOffEndOfEpisode", "via": "receivedDayOff"},
        {"to": "endcal", "via": "receivedEndCal", "timeField": "end_cal_time"},
        {"to": "endProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "dayOffEndOfEpisode": {
      "entry": [
        {
          "type": "conditionalSms",
          "if": {"type": "varEquals", "key": "isDayOff", "value": "true"},
          "templates": ["You have already sent \"DAYOFF\" for today."],
          "else": ["Got it, no TRE today! Thank you for telling us. Please still let us know your \"ENDCAL\" today."]
        },
        {
          "type": "treDayOffRollback",
          "suppressIf": {"type": "varEquals", "key": "isDayOff", "value": "true"}
        },
        {"type": "setVar", "key": "isDayOff", "value": "true"},
        {"type": "setVar", "key": "dayOffNoEndcal", "value": "true"}
      ],
      "transitions": [{"auto": true, "to": "endOfEpisode"}],
      "manualMoves": []
    },
    "resetEpisodeVariables": {
      "entry": [
        {"type": "incrementCounter", "key": "cycles"},
        {"type": "clearVars", "keys": ["isDayOff", "endcalRepeats", "wasSuccessfulFast", "wasAfter8pm", "dayOffNoEndcal"]}
      ],
      "transitions": [{"auto": true, "to": "waitStart"}],
      "manualMoves": [{"to": "waitStart", "via": "auto"}]
    },
    "endProtocol": {
      "terminal": true,
      "transitions": [],
      "manualMoves": []
    }
  }
}
$ssdef$::jsonb);

INSERT INTO fasting_staging.protocol_defs (file_name, def_name, definition) VALUES
('WeeklyMessage.v1.json', 'WeeklyMessage', $ssdef$
{
  "schemaVersion": 1,
  "name": "WeeklyMessage",
  "initialState": "initial",
  "timers": ["timeout1Week"],
  "settings": {
    "endProtocolEvent": "receivedEndProtocol",
    "collection": "none"
  },
  "restore": {
    "policy": "recompute-from-clock",
    "terminalStates": ["endProtocol"],
    "noSaveStateEvent": "receivedWaitWeek",
    "recomputeRules": [
      {"if": {"type": "always"}, "fire": "receivedWaitWeek"}
    ],
    "resetFire": "recomputeRules"
  },
  "eventMappings": {
    "sms": {"defaultAction": "ignore"}
  },
  "states": {
    "initial": {
      "transitions": [
        {"event": "receivedWaitWeek", "to": "waitWeek"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": []
    },
    "waitWeek": {
      "entry": [
        {"type": "setTimer", "timer": "timeout1Week", "deadline": {"type": "secondsToWeekday", "dayOfWeek": 5, "hour": 17, "minute": 0}}
      ],
      "transitions": [
        {"after": "timeout1Week", "to": "sendWeeklyMessage"},
        {"event": "receivedEndProtocol", "to": "endProtocol"}
      ],
      "manualMoves": []
    },
    "sendWeeklyMessage": {
      "entry": [
        {"type": "sendSms", "template": "Thank you for continuing to send us your \"STARTCAL\" and \"ENDCAL\" each day. Keep up the great work!"}
      ],
      "transitions": [{"auto": true, "to": "waitWeek"}],
      "manualMoves": []
    },
    "endProtocol": {
      "terminal": true,
      "transitions": [],
      "manualMoves": []
    }
  }
}
$ssdef$::jsonb);

SELECT file_name, def_name FROM fasting_staging.protocol_defs ORDER BY file_name;
